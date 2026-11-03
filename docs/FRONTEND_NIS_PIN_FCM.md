# Frontend Guide: NIS + PIN Login and Push Notifications (FCM)

How the two clients work with the backend on the `feature/nis-pin-login` branch:
- **Customer app** (React Native + Expo): sign in with NIS + PIN, the forced change of the
  temporary PIN, the session and PIN unlock, and push notifications through Firebase Cloud
  Messaging (FCM).
- **Staff web app:** the changes to the customer screens and the counter.

On this branch customers don't use Firebase Authentication and don't need an email.
Firebase is used **only to deliver push notifications**.

Everything not listed here (base URL, headers, money, dates, pagination, the withdrawal
approval flow) is unchanged; see [FRONTEND_HANDOFF.md](FRONTEND_HANDOFF.md). Backend
design: [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md).

## 1. What changed for the clients

| Area | Before (Firebase branch) | Now |
| --- | --- | --- |
| Customer login | Google / email via Firebase, then NIS activation | `POST /api/customer/login` (or `/api/login`) with `identifier` (NIS or account number) + `pin` |
| Email | Required, used to sign in | Optional contact field |
| First PIN | Customer creates it after activation | System generates a **temporary PIN**, shown once to the admin; the customer **must replace it** at the first login |
| Forgotten PIN | Admin removes it, customer creates a new one | Admin reset returns a **new temporary PIN** and ends the customer's sessions |
| New withdrawal request | Customer app polls | **Push notification (FCM)** plus polling as the fallback |
| Customer fields | `activation_status`, `activated_at`, `pin_set` | `pin_must_change` (boolean) |
| Error codes | `customer_app_not_activated`, `pin_not_set`, Firebase codes | `pin_change_required` (422 at the counter, 428 in the app) |

Removed endpoints: `/customer/auth/login`, `/customer/activation`, `/customer/pin/setup`.
New: `POST` / `DELETE /customer/push-tokens`.

## 2. Customer app (Expo)

### 2.1 Packages and project setup

| Need | Package |
| --- | --- |
| Token storage | `expo-secure-store` |
| FCM token and messages | `@react-native-firebase/app`, `@react-native-firebase/messaging` |
| Native config | `expo-build-properties` (iOS: `useFrameworks: "static"`) |
| Notification permission on Android 13+ and iOS | `messaging().requestPermission()` (iOS) and `PermissionsAndroid` `POST_NOTIFICATIONS` (Android 13+) |

React Native Firebase needs a **development build** (`npx expo prebuild` / EAS Build). It
does not run in Expo Go.

Firebase configuration (project `bank-mini-rumput`, see
[firebase-web-config.md](firebase-web-config.md)):

1. Register the apps in the Firebase project with the final bundle IDs:
   - **Android:** package name.
   - **iOS:** bundle ID.
   
   Ask the backend owner, or use the Firebase console (Project settings → General → Add app).
2. Download `google-services.json` (Android) and `GoogleService-Info.plist` (iOS) into the
   app project. These files identify the app and are not secret. The **service account key
   is never in the app**.
3. iOS only: upload an APNs authentication key in the Firebase console (Project settings →
   Cloud Messaging), otherwise iOS devices get no pushes.
4. `app.json` / `app.config.ts`:

```json
{
  "expo": {
    "android": { "package": "id.rumput.bankmini", "googleServicesFile": "./google-services.json" },
    "ios": { "bundleIdentifier": "id.rumput.bankmini", "googleServicesFile": "./GoogleService-Info.plist" },
    "plugins": [
      "@react-native-firebase/app",
      ["expo-build-properties", { "ios": { "useFrameworks": "static" } }]
    ]
  }
}
```

(`id.rumput.bankmini` is an example; use the real IDs.)

API base URL from the environment: `EXPO_PUBLIC_API_BASE_URL=https://bank.rumput.tech/api`.

### 2.2 Screens and flow

```text
App start
  ├─ no stored token, or expires_at passed ─▶ Login
  └─ token valid ─▶ GET /customer/me
        ├─ pin_must_change true ─▶ Change temporary PIN
        └─ otherwise ─▶ PIN unlock ─▶ Home (then register the push token)

Login ─▶ POST /customer/login
        ├─ must_change_pin true ─▶ Change temporary PIN ─▶ Home (register push token)
        └─ false ─▶ Home (register push token)
```

### 2.3 Login

```text
USER ACTION   Enters NIS (or account number) and the 6-digit PIN
→ ENDPOINT    POST /api/customer/login   (or POST /api/login with the same body)
→ REQUEST     {"identifier": "20260001", "pin": "483920"}
→ RESPONSE    200 {data: {actor: "customer", token, token_type: "Bearer", expires_at,
                          must_change_pin, customer: Customer}}
→ UI          Store token + expires_at in expo-secure-store.
              must_change_pin true → "Ganti PIN sementara" screen, nothing else.
```

| Response | Show |
| --- | --- |
| `422 errors.identifier = "NIS/nomor rekening atau PIN salah."` | Generic error. Wrong NIS and wrong PIN look the same, and remaining attempts are not shown at login |
| `422 errors.identifier = "Terlalu banyak percobaan PIN salah. Coba lagi pukul HH:MM."` | The lock message as is (3 wrong PINs anywhere lock the PIN for 15 minutes) |
| `422 errors.identifier = "Rekening ini sudah tidak aktif."` | Account closed |
| `422 errors.pin` | PIN must be 6 digits |
| `429` | Too many attempts, wait a minute (5 per minute per identifier + IP) |

Input: numeric keypad, PIN masked, cleared after each failed attempt. Never store the PIN.

### 2.4 Change the temporary PIN (forced)

Shown right after a login with `must_change_pin: true`, and whenever the API answers
`428` with `code: "pin_change_required"`.

```text
Fields: PIN sementara (current), PIN baru, Ulangi PIN baru — all 6 digits, masked.
→ ENDPOINT    POST /api/customer/pin   (with the session token)
→ REQUEST     {"current_pin": "483920", "new_pin": "715302", "new_pin_confirmation": "715302"}
→ RESPONSE    200 {"message": "PIN berhasil diubah."}
→ UI          The same session now has full access. Continue to Home and register the push token.
```

| Response | Show |
| --- | --- |
| `422 errors.new_pin` | `different`: must differ from the temporary PIN; `PIN terlalu mudah ditebak. Hindari angka yang sama semua atau berurutan.` (000000, 123456, 987654…); confirmation mismatch |
| `422 {message, remaining_attempts, pin_locked_until}` | Wrong temporary PIN; it counts toward the shared lock |

Explain on the screen: "PIN sementara dari petugas harus diganti. PIN baru hanya Anda yang
tahu; jangan beri tahu siapa pun, termasuk petugas."

Until the PIN is changed, every other customer endpoint answers:

```json
HTTP 428
{ "message": "Ganti PIN sementara Anda terlebih dahulu.", "code": "pin_change_required" }
```

Handle `428` globally in the HTTP client: go to the PIN change screen and **do not log
out**. Only `GET /customer/me`, `POST /customer/pin`, `POST /customer/logout` and
`/logout-all` work before the change.

### 2.5 Session, unlock, logout

- The session lasts `CUSTOMER_SESSION_MINUTES` (default 30 days, see `expires_at`). There
  is no refresh: on `401`, delete the token and show Login.
- **PIN unlock** on a cold start or after the app was in the background for a while
  (e.g. 5 minutes): `POST /api/customer/unlock {pin}`.
  - `200`: continue.
  - `422` with `remaining_attempts`, or with `pin_locked_until`: show it.
  - `428`: go to the PIN change screen.
- **Logout:** `POST /api/customer/logout`, then delete the stored token, even if the
  request failed. The server also deletes the push token registered with that session.
  "Keluar dari semua perangkat" is `POST /api/customer/logout-all`.
- **Forgotten PIN:** no self-service reset. The student asks an administrator, who gives
  them a new temporary PIN; all their sessions end, so the app shows Login.
- **Change PIN later** (settings): the same `POST /api/customer/pin` with the current PIN.

### 2.6 Push notifications (FCM)

When a teller creates a withdrawal request, the backend sends a push to every phone the
customer has registered:

| Field | Value |
| --- | --- |
| `notification.title` | `Permintaan penarikan` |
| `notification.body` | `Teller {name} meminta persetujuan penarikan Rp50.000. Buka aplikasi untuk menyetujui atau menolak.` |
| `data.type` | `withdrawal_authorization` |
| `data.id` | The request id (for `GET /api/customer/withdrawal-authorizations/{id}`) |

Android priority is high and the default sound is set.

**Register the token** after the login (and after the forced PIN change), on every app
start while logged in, and when FCM rotates it:

```ts
import messaging from '@react-native-firebase/messaging';
import { PermissionsAndroid, Platform } from 'react-native';

async function registerPush(api: Api) {
  if (Platform.OS === 'android' && Platform.Version >= 33) {
    const result = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.POST_NOTIFICATIONS);
    if (result !== PermissionsAndroid.RESULTS.GRANTED) return; // polling still works
  }
  if (Platform.OS === 'ios') {
    const status = await messaging().requestPermission();
    if (status === messaging.AuthorizationStatus.DENIED) return;
  }

  const token = await messaging().getToken();
  await api.post('/customer/push-tokens', { token, platform: Platform.OS }); // 201 new, 200 known
}

// FCM may rotate the token at any time.
messaging().onTokenRefresh(token => api.post('/customer/push-tokens', { token, platform: Platform.OS }));
```

**React to a push:**

```ts
// App in the foreground: FCM does not show a system notification. Show an in-app
// banner and refresh the approvals list.
messaging().onMessage(async message => {
  if (message.data?.type === 'withdrawal_authorization') {
    showBanner(message.notification?.title, message.notification?.body);
    refreshApprovals();
  }
});

// App in the background, user tapped the notification.
messaging().onNotificationOpenedApp(message => openApproval(message.data?.id));

// App was closed, opened by tapping the notification.
const initial = await messaging().getInitialNotification();
if (initial?.data?.id) openApproval(initial.data.id);
```

`openApproval(id)` goes through the normal checks:
1. Valid session.
2. PIN unlock if needed.
3. `GET /api/customer/withdrawal-authorizations/{id}`.
4. Show the request.

Never approve from the notification itself; the PIN is always required in the app.

**Turn pushes off** (settings switch): `DELETE /api/customer/push-tokens` with body
`{"token": "<current FCM token>"}` → `200`.

| Endpoint | Body | Response |
| --- | --- | --- |
| `POST /api/customer/push-tokens` | `{token (max 255), platform: "android" \| "ios"}` | `201` registered / `200` already known: `{"message": "Notifikasi aktif di perangkat ini."}` |
| `DELETE /api/customer/push-tokens` | `{token}` | `200 {"message": "Notifikasi dimatikan di perangkat ini."}` (also when unknown) |

Both need a session with the PIN already changed (`428` otherwise) and are limited to 10
per minute.

A token belongs to the session that registered it. Logout, logout-all, an admin PIN
reset, deactivation and session expiry remove it automatically. On a shared phone, the
last customer to log in gets the pushes.

**Push is a convenience, not a guarantee.** A push can be delayed or never arrive
(permission denied, battery savers, no Google Play services). Keep **polling** `GET
/api/customer/withdrawal-authorizations` every 3–5 s while the approvals screen is open,
exactly as before.

### 2.7 Customer object

```json
{
  "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso",
  "email": null, "pin_must_change": false,
  "class": "X", "group": "1", "major": { "id": 1, "code": "RPL", "major_name": "Rekayasa Perangkat Lunak" },
  "account_status": "active", "balance": 500000,
  "created_at": "...", "updated_at": "...", "deleted_at": null
}
```

## 3. Staff web app

### 3.1 New customer (admin)

- **Form:** NIS, name, class, major, group; **email optional**; **no PIN field** (sending
  `pin` returns `422 errors.pin = "PIN sementara dibuat otomatis oleh sistem."`).
- **Response 201:** `{data: Customer, temporary_pin: "483920"}`.
- Show `temporary_pin` **once** in a dialog with a clear warning that it can't be shown
  again. Offer copy and print (for example on the card print-out). Never store it in the
  browser, logs or analytics.
- Next step for the student: install the app, sign in with the NIS and this PIN, then
  replace it.

### 3.2 Reset PIN (admin)

- A button with a confirmation dialog: `POST /api/admin/customers/{id}/pin-reset` (no body).
- `200 {message: "PIN direset. Berikan PIN sementara kepada nasabah; nasabah wajib menggantinya saat masuk.", data: Customer, temporary_pin}`.
- Show `temporary_pin` once, as above. Explain that the student's app sessions have
  ended and that the student must replace the PIN at the next login. The reset also
  lifts a PIN lock.

### 3.3 Customer list, detail, edit

- **Show `pin_must_change`** as a badge ("PIN sementara"). The list filter
  `GET /api/customers?pin_must_change=1` finds students who never logged in or were reset.
- **Remove** `activation_status`, `activated_at`, `pin_set` and the `activation` filter.
- **Edit:** email is optional and editable at any time; `pin` is refused
  (`422 errors.pin = "PIN tidak dapat diubah di sini. Gunakan reset PIN untuk membuat PIN sementara baru."`).

### 3.4 Counter (teller)

- `GET /api/teller/customers/lookup` returns `pin_must_change`. When it is `true`,
  disable both ways of withdrawing and show "Nasabah belum mengganti PIN sementara".
  The temporary PIN was seen by staff, so it never authorizes money.
- If sent anyway, or if the state changed after the lookup, both
  `POST /api/teller/withdrawal-authorizations` and `POST /api/teller/withdrawals` answer:

```json
HTTP 422
{ "message": "Nasabah belum mengganti PIN sementara. Minta nasabah masuk ke aplikasi Bank Mini dan mengganti PIN terlebih dahulu.", "code": "pin_change_required" }
```

  No request is created and no PIN attempt is counted. Show `message` as is.
- **Remove** the handling of `customer_app_not_activated` and `pin_not_set`.
- Deposits work for every active account, including those with a temporary PIN.

## 4. Error codes

| `code` | Status | Where | Client action |
| --- | --- | --- | --- |
| `pin_change_required` | 428 | Any customer endpoint except me, PIN change, logout | Customer app: PIN change screen (don't log out) |
| `pin_change_required` | 422 | Teller withdrawal request / terminal withdrawal | Staff app: show `message`; withdrawal disabled |
| — (`errors.identifier`) | 422 | Customer login | Generic or lock message |
| — (`errors.new_pin`) | 422 | PIN change | Field message |
| — (`remaining_attempts`, `pin_locked_until`) | 422 | Unlock, PIN change, approval, terminal withdrawal | Wrong PIN / lock time |

## 5. Not implemented on this branch

- Customer login with Google or email.
- Self-service forgot-PIN.
- Pushes for anything other than new withdrawal requests (no push for approval results,
  deposits or statements).
- Web push (no browser notifications for the staff app).
- Notification history in the API.
