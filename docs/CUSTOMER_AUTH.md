# Customer Authentication (Firebase), Activation and PIN

How customers (students) get into the app:
- first-time activation with a Firebase sign-in and their NIS;
- the PIN they create afterwards;
- Firebase login (Google or email/password) and the long-lived backend session;
- the PIN as an app unlock, logout and deactivation;
- how to configure Firebase.

The backend sends **no email**: Firebase sends its own verification and
password-reset emails.

Source:
- `app/Services/`: `CustomerAuthService.php`, `CustomerActivationService.php`,
  `PinService.php`, `CustomerService.php`, `Firebase/KreaitFirebaseAuth.php`
- `app/Contracts/FirebaseAuth.php`
- `app/Http/Controllers/API/`: `Customer/AuthController.php`, `Customer/ActivationController.php`,
  `Customer/AccountController.php`, `Admin/CustomerController.php`
- `app/Exceptions/`: `CustomerAuthException.php`, `PinNotSetException.php`
- `app/Providers/AppServiceProvider.php`, `config/services.php`, `config/bank.php`
- migrations `2026_11_02_*`

Tests: `tests/Feature/`: `CustomerActivationTest.php`, `CustomerPinTest.php`,
`CustomerFirebaseLoginTest.php`, `CustomerSessionTest.php`, `KreaitFirebaseAuthTest.php`.

Staff authentication (username + password) is **unchanged**.

## 1. Architecture

| Concern | Owner |
| --- | --- |
| Email/password credentials, Google OAuth, email verification and password-reset emails, Firebase UID | **Firebase Authentication** (client SDK in the app) |
| Which account a Firebase user may use (activation), account status, verifying Firebase ID tokens, issuing and revoking Sanctum tokens, all banking operations, the PIN | **Laravel** |

- The backend never sees or stores a password, and sends no email.
- Banking endpoints keep using **Sanctum bearer tokens**. A Firebase ID token is only
  exchanged for a Sanctum token at login and activation, and checked again to create a
  PIN.
- The Firebase Admin SDK (`kreait/firebase-php`) runs only on the server with a service
  account. The service account key never reaches a client.
- After activation, customers are matched to a Firebase user **only by the UID** in a
  verified ID token, never by an email or ID in the request body.

```text
Admin creates the account (NIS, name, email; no PIN)  →  activation_status "pending", pin_set false

App: Firebase sign-in (Google | email+password; for email+password, Firebase sends
     its verification email and the user clicks it)
   → POST /api/customer/auth/login {id_token}  → 403 account_not_linked (first time)
   → app asks for the NIS
   → POST /api/customer/activation {nis, id_token}
        verified email == registered email of that NIS → binds firebase_uid once, Sanctum token
   → POST /api/customer/pin/setup {id_token, pin, pin_confirmation}   (customer creates the PIN)

Later logins: Firebase sign-in → POST /api/customer/auth/login {id_token} → Sanctum token (30 days)
While the session lasts: app unlock → POST /api/customer/unlock {pin}
Withdrawals: approve in the app with the PIN, or PIN at the teller terminal (by mode)
```

### Decisions

| # | Decision |
| --- | --- |
| A1 | Activation is tracked separately from `account_status`: `activated_at` / `activation_status` (`pending` \| `activated`). A new account is `active` for banking immediately, so it can receive a deposit at the counter before the student activates the app. |
| A2 | **Activation proof:** a Firebase ID token with a **verified** email equal to the email the administrator registered, plus the customer's **NIS**. No emailed link: Google accounts are verified, and for email/password Firebase sends the verification email itself. |
| A3 | **The customer creates the PIN** after activation (`POST /customer/pin/setup`), with a fresh Firebase ID token of the bound account as re-authentication. Administrators never set a PIN. A PIN can only exist on an activated account (database `CHECK`). Until it exists, nothing that needs a PIN works: no app unlock, no withdrawal approval, no terminal withdrawal (`422 pin_not_set`). |
| A4 | **The PIN is not a login credential.** NIS/account number + PIN never starts a session; the old `POST /customer/login` is removed. This deliberately departs from PRD FR-Auth-02. |
| A5 | **Forgotten PIN:** an administrator resets it (`POST /admin/customers/{id}/pin-reset`). This removes the PIN and lifts any lock, and the customer creates a new one in the app the same way as the first. |
| A6 | Customer sessions last `CUSTOMER_SESSION_MINUTES` (default 43200 = 30 days); staff tokens keep `SANCTUM_EXPIRATION` (480). |
| A7 | Firebase revocation and disabled status are checked (one Admin API call) on every login, activation and PIN setup, not on every banking request; banking requests rely on the Sanctum token, which deactivation deletes. |

## 2. Data

### `customers`

| Column | Type | Notes |
| --- | --- | --- |
| `email` | string, nullable, **unique** | Required by validation for new accounts; stored lowercase. Editable only while pending |
| `firebase_uid` | string(128), nullable, **unique** | Set once at activation. Hidden from all API responses |
| `email_verified_at` | timestamp, nullable | Set at activation (Firebase verified it) |
| `activated_at` | timestamp, nullable | Set at activation. `CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)` |
| `pin` | bcrypt, **nullable** | Null until the customer creates it, and after an administrator reset. `CHECK (pin IS NULL OR activated_at IS NOT NULL)` |

The API `Customer` object (`/customers`, `/customer/me`, login responses) and the teller
lookup (`CustomerSummary`) include `activation_status` (`pending` \| `activated`) and
`pin_set` (boolean). The `Customer` object also has `email` and `activated_at`.

## 3. Endpoints

All responses are JSON. Customer-auth refusals have the shape
`{"message": "...", "code": "..."}`; `code` is stable for the app to switch on.

### 3.1 Overview

| Method and path | Auth | Purpose |
| --- | --- | --- |
| `POST /api/customer/auth/login` | public | Firebase ID token → customer session |
| `POST /api/login` | public | Gateway: `{username, password}` (staff) or `{id_token}` (customer) |
| `POST /api/customer/activation` | public | NIS + Firebase ID token → bind the account (once) and start a session |
| `POST /api/customer/pin/setup` | customer | Create the PIN (first time, or after a reset) |
| `POST /api/customer/pin` | customer | Change the PIN with the current one |
| `POST /api/customer/unlock` | customer | PIN unlock during a session |
| `POST /api/customer/logout`, `/logout-all` | customer | Revoke this session / every session |
| `POST /api/admin/customers` | admin | `email` required; **no `pin`** (sending one is a 422) |
| `PUT\|PATCH /api/admin/customers/{id}` | admin | `email` while pending; **no `pin`** |
| `POST /api/admin/customers/{id}/pin-reset` | admin | Remove the PIN and its lock |
| `POST /api/admin/customers/{id}/deactivate` | admin | Close the account, revoke sessions, disable the Firebase user |
| `GET /api/customers` | admin, supervisor | Filter `activation=pending\|activated` |

Removed: `POST /api/customer/login`, `/customer/activation/inspect`, `/complete`,
`/resend`, and `/admin/customers/{id}/invitation`.

### 3.2 `POST /api/customer/auth/login`

```json
{ "id_token": "<firebase-id-token>" }
```

**200**

```json
{
  "data": {
    "actor": "customer",
    "token": "12|5pJ...",
    "token_type": "Bearer",
    "expires_at": "2026-12-02T02:00:00.000000Z",
    "customer": { "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso", "email": "budi.santoso@example.test", "activation_status": "activated", "activated_at": "...", "pin_set": true, "class": "X", "group": "1", "major": { "id": 1, "code": "RPL", "major_name": "Rekayasa Perangkat Lunak" }, "account_status": "active", "balance": 500000, "created_at": "...", "updated_at": "...", "deleted_at": null }
  }
}
```

If `customer.pin_set` is `false`, the app goes to PIN creation (§3.4).

| Status | `code` | Cause |
| --- | --- | --- |
| 401 | `firebase_token_invalid` | Bad signature, expired, other project, revoked, disabled or deleted Firebase user (one message for all) |
| 403 | `provider_not_allowed` | Sign-in provider other than `password` / `google.com` |
| 403 | `account_not_linked` | No activated account for this Firebase UID (first time: show the NIS activation screen). Never resolved by email |
| 403 | `email_not_verified` | The token's email is not verified |
| 403 | `account_inactive` | Account closed |
| 503 | `auth_unavailable` | Firebase unreachable or not configured |
| 422 / 429 | — | Missing `id_token` / rate limit |

Suspended accounts may log in to view their account.

### 3.3 `POST /api/customer/activation`

```json
{ "nis": "20260001", "id_token": "<firebase-id-token>" }
```

**200**: the same body as the login above (`activation_status: "activated"`, `pin_set: false`).

| Status | `code` | Cause |
| --- | --- | --- |
| 403 | `email_not_verified` | Email/password account whose email is not verified yet. The app calls `sendEmailVerification`, the customer clicks the link, then the app retries with `getIdToken(true)` |
| 403 | `activation_mismatch` | No customer with this NIS has this verified email registered. One answer for an unknown NIS, another person's email, and an account without email, so nothing is revealed. Message: `NIS tidak cocok dengan email akun ini. Masuk dengan email yang didaftarkan di bank mini, atau hubungi petugas.` |
| 403 | `account_inactive` | Account closed |
| 409 | `already_activated` | This account is already bound to a **different** Firebase account. With the same Firebase account, activation just signs in again (200) |
| 409 | `identity_already_linked` | This Firebase account is bound to another customer |
| 401 / 403 / 503 | `firebase_token_invalid`, `provider_not_allowed`, `auth_unavailable` | See login |
| 422 / 429 | — | Missing `nis` or `id_token` / rate limit |

Only the NIS is accepted (not the account number). The email comparison ignores case.
Activation runs in one transaction with the customer row locked (`SELECT … FOR UPDATE`);
`firebase_uid` is unique, so a customer can be claimed once and a Firebase account can
claim one customer.

### 3.4 PIN

`POST /api/customer/pin/setup` (customer session):

```json
{ "id_token": "<fresh firebase-id-token>", "pin": "135790", "pin_confirmation": "135790" }
```

| Status | Body |
| --- | --- |
| 201 | `{"message": "PIN berhasil dibuat."}` |
| 403 | `{"code": "identity_mismatch"}`: the ID token is of another Firebase account than the one bound to this customer |
| 401 | `{"code": "firebase_token_invalid"}` |
| 409 | `{"code": "pin_already_set", "message": "PIN sudah dibuat. Gunakan ubah PIN untuk menggantinya."}` |
| 422 | `errors.pin`: six digits, confirmed |

The fresh ID token means an unattended phone with a running session is not enough to set
a PIN (relevant after an administrator reset).

Everything that needs a PIN answers, while none exists:

```json
{ "message": "Nasabah belum membuat PIN. PIN dibuat oleh nasabah di aplikasi Bank Mini setelah akun diaktifkan.", "code": "pin_not_set" }
```

with status 422. That covers `POST /customer/unlock`, `POST /customer/pin`, approving a
withdrawal, `POST /teller/withdrawals` (terminal) and `POST /teller/withdrawal-authorizations`.
No wrong attempt is counted.

`POST /api/admin/customers/{id}/pin-reset` → **200**
`{"message": "PIN direset. Nasabah membuat PIN baru di aplikasi Bank Mini.", "data": Customer}`
(`pin_set: false`; the lock is lifted).

### 3.5 Session endpoints

| Endpoint | Body | Success | Notes |
| --- | --- | --- | --- |
| `POST /api/customer/unlock` | `{pin}` | `200 {"message":"PIN benar."}` | Wrong PIN `422 {message, remaining_attempts, pin_locked_until}`; shared lock. `422 pin_not_set` before a PIN exists. `401` once the session is over |
| `POST /api/customer/logout` | — | `200 {"message":"Berhasil keluar."}` | Current token only |
| `POST /api/customer/logout-all` | — | `200 {"message":"Berhasil keluar dari semua perangkat."}` | All tokens of the customer |

### 3.6 Admin

`POST /api/admin/customers` requires `email` (RFC email, max 255, unique
case-insensitively, stored lowercase). Sending `pin` →
`422 errors.pin = "PIN dibuat oleh nasabah di aplikasi Bank Mini setelah aktivasi."`.
**201** returns the Customer (`activation_status: "pending"`, `pin_set: false`). Tell the
student to install the app and sign in with that email.

`PATCH /api/admin/customers/{id}`:
- `email` is allowed while pending, for example to correct a typo. After activation it is
  refused: `422 errors.email = "Email tidak dapat diubah setelah rekening diaktifkan."`
- `pin` is always refused: `422 errors.pin = "PIN dibuat oleh nasabah di aplikasi Bank Mini. Gunakan reset PIN jika nasabah lupa PIN."`

### 3.7 Rate limits

| Limiter | Endpoints | Limit |
| --- | --- | --- |
| `login` | `/login`, `/staff/login`, `/customer/auth/login` | Staff: 5/min per username + IP; with only `id_token`: 30/min per IP |
| `customer-activation` | `/customer/activation` | 20/min per IP |
| `customer-unlock` | `/customer/unlock`, `/customer/pin/setup` | 5/min per customer |

The limits for Firebase logins and activation are per IP and generous because a class may
share one school Wi-Fi address. ID tokens cannot be guessed. Guessing NIS numbers gains
nothing, because the verified email must also match.

## 4. Lifecycle

```text
 admin creates (email, no PIN)        customer: Firebase sign-in + NIS          customer creates PIN
 [pending, no PIN] ─────────────────────────────────────────▶ [activated, no PIN] ──────────────▶ [activated, PIN]
        │  admin may correct the email                                ▲                               │
        │                                                             └──── admin resets the PIN ─────┘
        ▼
 admin deactivates (any state): account inactive + soft deleted, sessions revoked, Firebase user disabled
```

- **Pending accounts** receive deposits at the counter. A withdrawal of any kind needs an
  activated app and a PIN.
- **No reactivation endpoint.** A closed account cannot be activated, and its Firebase
  identity cannot log in even if it is re-enabled in the Firebase console, because the
  backend checks the account first.

## 5. Firebase revocation and `checkRevoked`

`KreaitFirebaseAuth::verifyIdToken()` verifies the token in these steps:
1. The SDK verifies the signature and claims.
2. Audience and issuer are checked again against `FIREBASE_PROJECT_ID`, and the sign-in
   provider against the allow-list.
3. The Firebase user is loaded once, to refuse **disabled** users and tokens whose
   `auth_time` is before the user's `tokensValidAfterTime`. This is the SDK's
   `checkIfRevoked` rule; the SDK's own flag doesn't check "disabled", so the adapter does
   both with one lookup.

This runs at **login, activation and PIN setup**. It doesn't run on banking requests:
those use the Sanctum token, which deactivation deletes and `logout`/`logout-all` revoke.
So revoking a user in the Firebase console (or a password change, which revokes other
Firebase sessions) doesn't end existing backend sessions; use `logout-all` or deactivation
for that.

## 6. Firebase Console configuration

The project in use is `bank-mini-rumput`. What's already configured there, and the web
config for the customer app, is in [firebase-web-config.md](firebase-web-config.md).

1. **Project.** Create or choose a Firebase project. Its ID is `FIREBASE_PROJECT_ID`.
2. **Sign-in providers** (Authentication → Sign-in method):
   - Enable **Email/Password**. The "Email link (passwordless)" option isn't used; on the
     free plan it is limited to 5 emails a day.
   - Enable **Google** and set the project support email.
   - Leave other providers disabled; the backend refuses them anyway (see
     `FIREBASE_ALLOWED_PROVIDERS`).
3. **One account per email** (Authentication → Settings → User account linking): keep the
   default "Link accounts that use the same email". The backend never links accounts by
   email; the client must sign in with the existing method and call `linkWithCredential`
   (see FRONTEND_HANDOFF.md §15.10).
4. **Email enumeration protection** (Authentication → Settings → User actions): keep it
   **enabled**.
5. **Authorized domains** (Authentication → Settings): the domains that serve the customer
   app, plus `localhost` for development.
6. **Email templates** (Authentication → Templates): template language Indonesian and the
   sender name. These are the only emails customers receive:
   - **Address verification** (email/password sign-up): 1,000 a day on the free plan.
   - **Password reset:** 150 a day.
7. **Password policy** (optional, Authentication → Settings): minimum length and
   character requirements.
8. **Apps:** register the client apps (Project settings → General):
   - A **web app** gives the web config (`apiKey`, `authDomain`, `projectId`, `appId`).
   - **Android and iOS apps** are needed for native Google Sign-In. Android: add the SHA-1
     and SHA-256 fingerprints of the signing keys. iOS: add the reversed client ID URL
     scheme.
9. **Google OAuth consent screen** (Google Cloud console → Google Auth Platform): set the
   app name, support email and authorized domains, and for production publish the app.
   Native Google Sign-In libraries need the project's **web client ID**.
10. **Service account** (Project settings → Service accounts → Generate new private key):
    - Save the JSON on the server outside the web root, for example
      `storage/app/private/firebase-credentials.json` (git-ignored), and set
      `FIREBASE_CREDENTIALS` to its path.
    - Treat it like a database password.
    - To rotate it, generate a new key and delete the old one.

## 7. Email

The backend sends no email and needs no SMTP server; the `MAIL_*` variables are unused.
Customers only receive Firebase's own emails (verification, password reset), sent by
Firebase from the templates in §6.6.

## 8. Environment variables

```dotenv
# Customer app sessions (minutes; default 30 days). Staff: SANCTUM_EXPIRATION (480).
CUSTOMER_SESSION_MINUTES=43200

# Firebase (server side)
FIREBASE_PROJECT_ID=bank-mini-rumput
FIREBASE_CREDENTIALS=storage/app/private/firebase-credentials.json
FIREBASE_ALLOWED_PROVIDERS=password,google.com
# FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099   # local emulator only
```

Client apps need the Firebase **web config** (not secret) through their own environment,
for example `EXPO_PUBLIC_FIREBASE_*` (Expo), plus the Google web client ID for native
Google Sign-In.

## 9. Local development without a Firebase project

The Firebase Auth emulator works with a `demo-` project ID and needs no real credentials:

```bash
# firebase.json: {"emulators": {"auth": {"port": 9099}}}
firebase emulators:start --only auth --project demo-bank-mini
```

```dotenv
FIREBASE_PROJECT_ID=demo-bank-mini
FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099
FIREBASE_CREDENTIALS=storage/app/private/firebase-dummy.json   # any well-formed service account JSON
```

The client SDK connects with `connectAuthEmulator(auth, "http://127.0.0.1:9099")`. The
emulator accepts fake Google sign-ins and lets you mark emails verified. It doesn't send
real email; verification links appear in the emulator UI.

## 10. Migration instructions

1. `composer install` (adds `kreait/firebase-php`).
2. `php artisan migrate`. The three `2026_11_02_*` migrations:
   - add the nullable Firebase columns;
   - make `pin` nullable and **clear the PIN of every account that isn't activated** (such
     a customer creates one after activating);
   - drop the old activation-link table.
   
   Balances and history are untouched. Rollback (`migrate:rollback --step=1`) restores
   NOT NULL with unusable random PINs for accounts without one.
3. Configure Firebase (§6) and the environment (§8).
4. For each existing customer without an email, the administrator adds it
   (`PATCH /api/admin/customers/{id}` `{email}`).
5. Tell students how to start:
   1. Install the app.
   2. Sign in with Google or email/password, using the email the school registered.
   3. Enter the NIS.
   4. Create a PIN.
   
   NIS + PIN no longer signs in.
6. Staff frontend: remove the PIN field from "new customer" and "edit customer", and turn
   "reset PIN" into a button calling `POST /admin/customers/{id}/pin-reset`.
7. `php artisan migrate:fresh --seed` (development) creates demo customers with
   `@example.test` emails, pending and without a PIN. To try activation, change one demo
   customer's email to a real address first.

## 11. Security considerations

- **Claiming an account** needs control of the registered mailbox (a Firebase-verified
  email equal to the registered one) and the NIS. An email match alone never links an
  identity: the customer must name the account.
- **No enumeration:**
  - `activation_mismatch` is the same for an unknown NIS and for another person's email.
  - Login codes describe only the caller's own identity.
  - Admin validation (`email` unique) is visible to administrators only.
  - Keep Firebase email enumeration protection on.
- **PIN creation** needs a session and a fresh Firebase ID token of the bound account, and
  is only possible while no PIN exists.
- **PIN brute force:** a shared lock (3 wrong → 15 min) across unlock, withdrawal approval,
  terminal withdrawal and PIN change, plus rate limits. The PIN is never a login credential.
- **Long sessions:** a stolen session token works until it expires (30 days by default) or
  is revoked. Mitigations:
  - tokens in secure storage;
  - the PIN unlock in the app;
  - `logout-all` and deactivation.
  
  Consider a shorter `CUSTOMER_SESSION_MINUTES` if devices are shared.
- **PIN unlock is a client-side gate:** the server verifies the PIN, but the API accepts
  the token without a recent unlock. It protects a lost, unlocked phone's UI, not a copied
  token.
- **Secrets:**
  - The service account JSON lives only in a file outside the repository (`.gitignore`
    covers `*firebase-adminsdk*.json` and `firebase-credentials.json`).
  - The Firebase web config is public by design.
- **Firebase outages:**
  - Login, activation and PIN setup return `503 auth_unavailable`.
  - Banking with an existing session and counter operations keep working.
  - Deactivation still succeeds, and logs a warning if the Firebase user can't be disabled.
- **Not covered:** multi-factor authentication, device binding, revoking backend sessions
  automatically when Firebase sessions are revoked (§5), push notifications.
