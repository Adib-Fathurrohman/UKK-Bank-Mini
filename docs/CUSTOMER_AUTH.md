# Customer Authentication (NIS + PIN) and Push Notifications (FCM)

How customers (students) get into the app on this branch:
- **Login:** NIS or account number + PIN, with a temporary PIN the system generates and
  the customer must replace at the first login. No email is required.
- **Sessions:** a long-lived backend session, with the PIN as an app unlock.
- **Push:** Firebase Cloud Messaging (FCM) pushes new withdrawal requests to the phone.

Firebase Authentication is **not** used. Firebase is only the push channel.

Source:
- `app/Services/`: `CustomerAuthService.php`, `PinService.php`, `CustomerService.php`,
  `WithdrawalAuthorizationService.php`, `Push/FcmPushNotifier.php`
- `app/Contracts/PushNotifier.php`
- `app/Http/Middleware/EnsurePinChanged.php`
- `app/Http/Controllers/API/`: `Customer/AuthController.php`, `Customer/AccountController.php`,
  `Customer/PushTokenController.php`, `Admin/CustomerController.php`
- `app/Rules/NotWeakPin.php`, `app/Exceptions/PinChangeRequiredException.php`
- `app/Models/CustomerPushToken.php`
- migration `2026_11_03_000001_customer_nis_pin_login.php`

Tests: `tests/Feature/`: `CustomerAuthTest.php`, `CustomerFirstLoginTest.php`,
`CustomerLoginLockTest.php`, `CustomerSessionTest.php`, `CustomerPushTokenTest.php`,
`FcmPushNotifierTest.php`, `WithdrawalAuthorizationTest.php`.

Client contract: [FRONTEND_NIS_PIN_FCM.md](FRONTEND_NIS_PIN_FCM.md). Staff authentication
(username + password) is unchanged.

## 1. Design

```text
Admin creates the account (email optional) ─▶ temporary PIN generated, returned once
Student: POST /api/customer/login {identifier: NIS or account number, pin}
   ─▶ session token (30 days) + must_change_pin: true
   ─▶ POST /api/customer/pin {current_pin, new_pin, new_pin_confirmation}  (forced)
   ─▶ full access: unlock, balance, history, approvals, push token registration
Teller creates a withdrawal request ─▶ FCM push to the customer's phones (after the response)
```

| # | Decision |
| --- | --- |
| N1 | **Login is NIS or account number + PIN** (`POST /customer/login`, or `/login` with `identifier`). No email or Firebase account needed. Wrong PINs count toward the shared PIN lock (3 wrong → 15 minutes). The error doesn't say whether the NIS exists; only an actual lock is revealed. |
| N2 | **The system generates the first PIN** (random 6 digits, never a weak one) when an account is opened. It is returned **once** in the admin response (`temporary_pin`) and stored only as a bcrypt hash. Administrators never type a PIN. |
| N3 | **Forced change.** While `pin_must_change` is true, a customer session can only call `GET /customer/me`, `POST /customer/pin`, `/logout` and `/logout-all`. Everything else answers `428 {code: "pin_change_required"}` (`EnsurePinChanged`). Changing the PIN (different from the current one, not weak) clears the flag; the same session then has full access. |
| N4 | **A temporary PIN never authorizes money.** Staff have seen it, so `POST /teller/withdrawal-authorizations` and `POST /teller/withdrawals` answer `422 pin_change_required` for such a customer, without counting a PIN attempt. |
| N5 | **Forgotten PIN:** `POST /admin/customers/{id}/pin-reset` generates a new temporary PIN (returned once), sets `pin_must_change`, lifts the lock and **ends every session** of the customer. |
| N6 | **Weak PINs refused** when the customer chooses one: one repeated digit (`000000`) and straight runs (`123456`, `987654`). |
| N7 | **Push (FCM)** for new withdrawal requests, sent after the response (Laravel `defer`), so FCM never delays or fails the teller's request. Polling stays the source of truth. |
| N8 | **Push tokens belong to a session** (`personal_access_token_id`, cascade on delete): logout, logout-all, PIN reset, deactivation and token expiry remove them. A token seen again (shared phone) moves to the customer who registered it last. |
| N9 | Customer sessions last `CUSTOMER_SESSION_MINUTES` (default 43200 = 30 days); staff tokens keep `SANCTUM_EXPIRATION` (480). Inside a session the PIN unlocks the app (`POST /customer/unlock`) and authorizes withdrawals. |

## 2. Data

### `customers`

| Column | Notes |
| --- | --- |
| `email` | Nullable, unique (lowercase). Optional contact information |
| `pin` | bcrypt, NOT NULL |
| `pin_must_change` | Boolean. True after creation and after an admin reset, false once the customer has chosen a PIN |

API: the `Customer` object and the teller lookup include `pin_must_change`. The admin
create and reset responses add `temporary_pin` (only there).

### `customer_push_tokens`

| Column | Notes |
| --- | --- |
| `customer_id` | FK, cascade on delete |
| `personal_access_token_id` | The session that registered it; FK to `personal_access_tokens`, cascade on delete |
| `token` | FCM registration token, unique, max 255 |
| `platform` | `android` or `ios` |

## 3. Endpoints

| Method and path | Auth | Purpose |
| --- | --- | --- |
| `POST /api/customer/login` | public | `{identifier, pin}` → session (`must_change_pin`) |
| `POST /api/login` | public | Gateway: `{username, password}` (staff) or `{identifier, pin}` (customer) |
| `POST /api/customer/pin` | customer | Change the PIN (also replaces the temporary one) |
| `POST /api/customer/unlock` | customer, PIN changed | PIN unlock during a session |
| `POST`, `DELETE /api/customer/push-tokens` | customer, PIN changed | Register / remove the phone's FCM token |
| `GET /api/customer/me`, `POST /api/customer/logout`, `/logout-all` | customer | Allowed with the temporary PIN |
| `POST /api/admin/customers` | admin | Email optional, no `pin`; response has `temporary_pin` |
| `POST /api/admin/customers/{id}/pin-reset` | admin | New temporary PIN (`temporary_pin`), sessions ended |
| `GET /api/customers?pin_must_change=1` | admin, supervisor | Who still has a temporary PIN |

### 3.1 `POST /api/customer/login`

```json
{ "identifier": "20260001", "pin": "483920" }
```

**200**

```json
{
  "data": {
    "actor": "customer",
    "token": "12|5pJ...",
    "token_type": "Bearer",
    "expires_at": "2026-12-03T02:00:00.000000Z",
    "must_change_pin": true,
    "customer": { "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso", "email": null, "pin_must_change": true, "...": "..." }
  }
}
```

**422** on `identifier`:

| Message | Cause |
| --- | --- |
| `NIS/nomor rekening atau PIN salah.` | Unknown identifier, wrong PIN or closed (soft-deleted) account. Remaining attempts not revealed |
| `Terlalu banyak percobaan PIN salah. Coba lagi pukul HH:MM.` | PIN locked (3 wrong in a row, anywhere) |
| `Rekening ini sudah tidak aktif.` | `inactive` account that is not soft-deleted |

The account number is tried first, then the NIS (same as the teller lookup), and both
lead to the same lock counter. Suspended accounts may log in to view their account.

### 3.2 `POST /api/customer/pin`

`{current_pin, new_pin, new_pin_confirmation}`. `new_pin`: 6 digits, different from
`current_pin`, confirmed, not weak (`PIN terlalu mudah ditebak. Hindari angka yang sama
semua atau berurutan.`). A wrong `current_pin` → `422 {remaining_attempts,
pin_locked_until}`. **200** `{"message": "PIN berhasil diubah."}` and `pin_must_change`
becomes false.

### 3.3 Before the PIN change

```json
HTTP 428
{ "message": "Ganti PIN sementara Anda terlebih dahulu.", "code": "pin_change_required" }
```

428 (Precondition Required) rather than 403, so clients that log out on 403 don't.

### 3.4 Admin

- `POST /api/admin/customers` → **201**
  `{"data": Customer (pin_must_change true), "temporary_pin": "483920"}`. Sending `pin` →
  `422 errors.pin = "PIN sementara dibuat otomatis oleh sistem."`.
- `POST /api/admin/customers/{id}/pin-reset` → **200**
  `{"message": "PIN direset. Berikan PIN sementara kepada nasabah; nasabah wajib menggantinya saat masuk.", "data": Customer, "temporary_pin": "..."}`.
- `PATCH /api/admin/customers/{id}` refuses `pin`:
  `PIN tidak dapat diubah di sini. Gunakan reset PIN untuk membuat PIN sementara baru.`

### 3.5 Push tokens

| Endpoint | Body | Response |
| --- | --- | --- |
| `POST /api/customer/push-tokens` | `{token, platform: android \| ios}` | `201` (new) / `200` (known): `{"message": "Notifikasi aktif di perangkat ini."}` |
| `DELETE /api/customer/push-tokens` | `{token}` | `200 {"message": "Notifikasi dimatikan di perangkat ini."}`; only the caller's own tokens are removed |

### 3.6 Rate limits

| Limiter | Endpoints | Limit |
| --- | --- | --- |
| `login` | `/login`, `/staff/login`, `/customer/login` | 5/min per username or identifier + IP |
| `customer-unlock` | `/customer/unlock` | 5/min per customer |
| `customer-push-token` | `/customer/push-tokens` | 10/min per customer |

## 4. Push notifications (FCM)

| Item | Detail |
| --- | --- |
| Trigger | `WithdrawalAuthorizationService::create()`, after the request is committed, through `defer()` (after the response is sent) and `rescue()` |
| Recipients | Every token in `customer_push_tokens` of that customer (`sendMulticast`) |
| Message | `notification` (title `Permintaan penarikan`, body with teller name and amount), `data` `{type: "withdrawal_authorization", id: <request id>}`, high priority, default sound |
| Clean-up | Tokens FCM reports as invalid or unknown (app uninstalled, token rotated) are deleted |
| Failure | Logged as a warning (`Push notification could not be sent.`), never thrown; the app still polls |
| Not configured | Without `FIREBASE_CREDENTIALS` nothing is sent and a warning is logged when a customer with registered phones gets a request |
| Credentials | The service account JSON (`FIREBASE_CREDENTIALS`), server only. Verified against project `bank-mini-rumput` with a validate-only send |

The interface `App\Contracts\PushNotifier` keeps FCM replaceable; tests bind
`Tests\Support\FakePushNotifier` (`fakePush()`).

### Firebase setup for FCM

1. **Project:** `bank-mini-rumput` (already created), with the service account key on the
   server.
2. **Firebase Cloud Messaging API (V1):** enabled by default on new projects. It is
   enabled here: the validate-only send passed.
3. **App registration:** register the Android and iOS apps with the final bundle IDs; the
   app needs `google-services.json` / `GoogleService-Info.plist`.
4. **iOS:** upload an APNs authentication key (Project settings → Cloud Messaging).
5. **Firebase Authentication** providers configured earlier are unused on this branch and
   can stay or be disabled.

## 5. Environment variables

```dotenv
CUSTOMER_SESSION_MINUTES=43200
FIREBASE_PROJECT_ID=bank-mini-rumput
FIREBASE_CREDENTIALS=storage/app/private/firebase-credentials.json
```

`FIREBASE_ALLOWED_PROVIDERS`, `CUSTOMER_ACTIVATION_*` and all `MAIL_*` settings are not
used.

## 6. Migration

`php artisan migrate` runs `2026_11_03_000001_customer_nis_pin_login`:
- **Dropped:** `firebase_uid`, `email_verified_at`, `activated_at` and their CHECK
  constraints.
- **Added:** `pin_must_change`; `pin` is NOT NULL again.
- **Accounts without a PIN** get an unusable random hash and `pin_must_change = true`. An
  administrator must reset their PIN to give them a temporary one.
- **Accounts whose customer had already chosen a PIN** keep it.
- **Created:** `customer_push_tokens`.

Balances and history are untouched. Rollback (`migrate:rollback --step=1`) restores the
Firebase columns and makes every customer pending without a PIN.

## 7. Security considerations

- **PIN brute force:** one shared lock (3 wrong → 15 minutes) across login, unlock, PIN
  change, approval and terminal withdrawal, saved even when the request fails, plus
  per-identifier rate limits. The login reveals neither the remaining attempts nor
  whether a NIS exists.
- **Temporary PINs** are random, shown once, hashed, and can't move money. The customer
  must replace them before anything else.
- **NIS + PIN is weaker than a password or a Google account.** NIS numbers are not
  secret, so the 6-digit PIN and the lock carry the security.
  - Mitigations: the lock, the weak-PIN rule, PIN unlock in the app, `logout-all`, and
    admin reset (which ends all sessions).
  - Consider a shorter `CUSTOMER_SESSION_MINUTES` if devices are shared.
- **PIN unlock is a client-side gate:** the API accepts a valid session token without a
  recent unlock.
- **Push content** shows the teller name and amount on the lock screen. The notification
  is only a hint: approving always needs the app, the session and the PIN.
- **Secrets:** the service account JSON stays on the server, git-ignored. The app's
  Firebase config files are not secret.
