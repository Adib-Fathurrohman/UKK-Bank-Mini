# Customer Authentication (Firebase) and Activation

How customers (students) get into the app: first-time activation from an emailed
invitation, Firebase login (Google or email/password), the long-lived backend session,
the PIN as an app unlock, logout, deactivation, and how to configure Firebase and SMTP.

Source: `app/Services/CustomerAuthService.php`, `CustomerActivationService.php`,
`CustomerService.php`, `app/Services/Firebase/KreaitFirebaseAuth.php`,
`app/Contracts/FirebaseAuth.php`, `app/Http/Controllers/API/Customer/AuthController.php`,
`ActivationController.php`, `Admin/CustomerController.php`, `app/Exceptions/CustomerAuthException.php`,
`app/Mail/*`, `resources/views/mail/*`, `app/Providers/AppServiceProvider.php`,
`config/services.php`, `config/bank.php`, migrations `2026_11_02_*`. Tests:
`tests/Feature/CustomerActivationTest.php`, `CustomerFirebaseLoginTest.php`,
`CustomerSessionTest.php`, `KreaitFirebaseAuthTest.php`.

Staff authentication (username + password) is **unchanged**.

## 1. Architecture

| Concern | Owner |
| --- | --- |
| Email/password credentials, Google OAuth, email verification, password reset, Firebase UID | **Firebase Authentication** (client SDK in the app) |
| Which account a Firebase user may use (activation), account status, verifying Firebase ID tokens, issuing and revoking Sanctum tokens, all banking operations, the PIN | **Laravel** |

- The backend never sees or stores a password. There is no password column; the PIN
  (bcrypt) is unchanged.
- Banking endpoints keep using **Sanctum bearer tokens**. A Firebase ID token is only
  exchanged for a Sanctum token at login and activation.
- The Firebase Admin SDK (`kreait/firebase-php`) runs only on the server with a service
  account. The service account key never reaches a client.
- Customers are matched to a Firebase user **only by the UID** in a verified ID token,
  never by an email or ID in the request body.

```text
Invitation email ──▶ activation page ──▶ Firebase sign-in (Google | email+password, verified)
                                              │ ID token
                                              ▼
                           POST /api/customer/activation/complete {token, id_token}
                                              │ binds firebase_uid once, issues Sanctum token
                                              ▼
Later logins: Firebase sign-in ─▶ POST /api/customer/auth/login {id_token} ─▶ Sanctum token (30 days)
While the session lasts: app unlock ─▶ POST /api/customer/unlock {pin}
Withdrawals: POST /api/customer/withdrawal-authorizations/{id}/approve {pin} (unchanged)
```

### Decisions

| # | Decision |
| --- | --- |
| A1 | Activation is tracked separately from `account_status`: `activated_at` / `activation_status` (`pending` \| `activated`). A new account is `active` for banking immediately, so it can receive a deposit at the counter before the student activates the app. `account_status` keeps `active` / `suspended` / `inactive`. |
| A2 | NIS/account number + PIN **no longer starts a session**. After a correct PIN it answers `403 activation_required` (with the masked registered email) or `403 firebase_login_required`. The PIN check, the shared lock and the error messages are unchanged. This deliberately departs from PRD FR-Auth-02 (login with NIS + PIN). |
| A3 | Customer sessions last `CUSTOMER_SESSION_MINUTES` (default 43200 = 30 days); staff tokens keep `SANCTUM_EXPIRATION` (480). Inside a session the PIN unlocks the app (`POST /customer/unlock`) and authorizes withdrawals. |
| A4 | New accounts require an email; older accounts without one keep working at the counter and become activatable once an administrator adds an email. |
| A5 | Emails are sent synchronously after the database commit. A sending failure is reported (`mail_sent: false`) and never rolls back the account; the invitation can be resent. |
| A6 | Firebase revocation and disabled status are checked (one Admin API call) on every login and activation, not on every banking request; banking requests rely on the Sanctum token, which deactivation deletes. |

## 2. Data

### `customers` (new columns)

| Column | Type | Notes |
| --- | --- | --- |
| `email` | string, nullable, **unique** | Required by validation for new accounts; stored lowercase. Not editable after activation |
| `firebase_uid` | string(128), nullable, **unique** | Set once at activation. Hidden from all API responses |
| `email_verified_at` | timestamp, nullable | Set at activation (Firebase verified it) |
| `activated_at` | timestamp, nullable | Set at activation. `CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)` |

API `Customer` object gains `email`, `activation_status` (`pending` \| `activated`) and
`activated_at`.

### `customer_activation_tokens` (new table)

| Column | Notes |
| --- | --- |
| `customer_id` | FK, restrict on delete |
| `token_hash` | SHA-256 of the token, unique. The plain token exists only in the email |
| `email` | Address the link was sent to; activation must match it |
| `issued_by` | Administrator id, or null when the customer requested the link |
| `expires_at` | `CUSTOMER_ACTIVATION_TOKEN_HOURS` (default 24) after issue |
| `used_at` / `revoked_at` | A link is usable only while both are null and it has not expired |

Tokens are 64 hex characters (256 bits from `random_bytes`). Issuing a new link revokes
every outstanding one; activation marks the link used and revokes the others; changing
the email or closing the account revokes them all. Rows are kept as an audit record.

## 3. Endpoints

All responses are JSON. Customer-auth refusals have the shape
`{"message": "...", "code": "...", ...extra}`; `code` is stable for the app to switch on.

### 3.1 New and changed

| Method and path | Auth | Purpose |
| --- | --- | --- |
| `POST /api/customer/auth/login` | public | Firebase ID token → customer session |
| `POST /api/login` | public | Gateway: now also accepts `{id_token}` (customer). `{identifier, pin}` behaves like `/customer/login` |
| `POST /api/customer/login` | public | **Changed:** NIS/account number + PIN check only; never returns a token |
| `POST /api/customer/activation/inspect` | public | Activation page data for a link |
| `POST /api/customer/activation/complete` | public | Bind the Firebase account (once) and start a session |
| `POST /api/customer/activation/resend` | public | Pending customer requests a new link with NIS + PIN |
| `POST /api/customer/unlock` | customer | PIN unlock during a session |
| `POST /api/customer/logout-all` | customer | Revoke every session of the customer |
| `POST /api/admin/customers` | admin | **Changed:** `email` required; emails the invitation; response has `invitation` |
| `PUT\|PATCH /api/admin/customers/{id}` | admin | **Changed:** accepts `email` (pending accounts only; revokes outstanding links) |
| `POST /api/admin/customers/{id}/invitation` | admin | Resend the invitation |
| `POST /api/admin/customers/{id}/deactivate` | admin | **Changed:** also revokes links and disables the Firebase user |
| `GET /api/customers` | admin, supervisor | **Changed:** filter `activation=pending\|activated`; objects include the new fields |

`POST /api/customer/logout`, `GET /api/customer/me` and all banking endpoints are unchanged.

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
    "customer": { "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso", "email": "budi.santoso@example.test", "activation_status": "activated", "activated_at": "...", "class": "X", "group": "1", "major": { "id": 1, "code": "RPL", "major_name": "Rekayasa Perangkat Lunak" }, "account_status": "active", "balance": 500000, "created_at": "...", "updated_at": "...", "deleted_at": null }
  }
}
```

The shape is the old login response plus `expires_at` (illustrative values).

| Status | `code` | Cause |
| --- | --- | --- |
| 401 | `firebase_token_invalid` | Bad signature, expired, other project, revoked, disabled or deleted Firebase user (one message for all) |
| 403 | `provider_not_allowed` | Sign-in provider other than `password` / `google.com` |
| 403 | `account_not_linked` | No activated account for this Firebase UID. Never resolved by email |
| 403 | `email_not_verified` | The token's email is not verified |
| 403 | `account_inactive` | Account closed |
| 503 | `auth_unavailable` | Firebase unreachable or not configured |
| 422 / 429 | — | Missing `id_token` / rate limit |

Suspended accounts may log in (as before) to view their account.

### 3.3 `POST /api/customer/login` (NIS/account number + PIN)

Same body, validation, PIN lock and `422` messages as before. After a correct PIN:

| Status | Body |
| --- | --- |
| 403 | `{"message": "Rekening belum diaktifkan. Buka tautan aktivasi yang dikirim ke b***@gmail.com.", "code": "activation_required", "email": "b***@gmail.com"}` (`email` null and a "contact the administrator" message when no email is registered) |
| 403 | `{"message": "Masuk dengan akun Google atau email yang terhubung. PIN dipakai untuk membuka aplikasi dan bertransaksi.", "code": "firebase_login_required"}` |

It is never `200`. Wrong identifier/PIN responses do not reveal activation state.

### 3.4 Activation

`POST /api/customer/activation/inspect` `{ "token": "<64 chars from ?token=>" }`

```json
{ "data": { "full_name": "Budi Santoso", "account_number": "RPL-2026-001", "email": "budi.santoso@example.test", "expires_at": "2026-11-03T02:00:00.000000Z", "providers": ["password", "google.com"] } }
```

`POST /api/customer/activation/complete` `{ "token": "...", "id_token": "..." }` → **200**
with the same body as the login above (`activation_status: "activated"`).

| Status | `code` | Cause | Link still usable? |
| --- | --- | --- | --- |
| 404 | `activation_invalid` | Unknown, used, revoked, account closed or already activated | — |
| 410 | `activation_expired` | Past `expires_at` | No (request a new one) |
| 401 | `firebase_token_invalid` | See login | Yes |
| 403 | `provider_not_allowed` | See login | Yes |
| 403 | `email_not_verified` | Email/password account not verified yet | Yes |
| 403 | `email_mismatch` (+ `email`, masked) | Firebase email ≠ registered email (compared lowercase) | Yes |
| 409 | `identity_already_linked` | This Firebase account is bound to another customer | Yes |
| 503 | `auth_unavailable` | Firebase down | Yes |

The link is checked first, so a bad link is reported as `activation_invalid` even when
the Firebase token is also bad. Activation runs in one transaction with the token row and
the customer row locked (`SELECT … FOR UPDATE`); `firebase_uid` is unique, so a customer
can be claimed once and a Firebase account can claim one customer. On success an
"account activated" security email is sent to the registered address.

`POST /api/customer/activation/resend` `{ "identifier": "...", "pin": "......" }` (pending
customers): **200** `{message, data: {email (masked), expires_at, mail_sent}}`. Wrong PIN →
the usual `422` (counts toward the PIN lock); activated → `403 firebase_login_required`;
no email → `403 activation_required` with `email: null`.

### 3.5 Session endpoints

| Endpoint | Body | Success | Notes |
| --- | --- | --- | --- |
| `POST /api/customer/unlock` | `{pin}` | `200 {"message":"PIN benar."}` | Wrong PIN `422 {message, remaining_attempts, pin_locked_until}`; shared lock. `401` once the session is over |
| `POST /api/customer/logout` | — | `200 {"message":"Berhasil keluar."}` | Current token only (unchanged) |
| `POST /api/customer/logout-all` | — | `200 {"message":"Berhasil keluar dari semua perangkat."}` | All tokens of the customer |

### 3.6 Admin

`POST /api/admin/customers` adds `email` (required, RFC email, max 255, unique
case-insensitively; stored lowercase). **201**:

```json
{
  "data": { "...": "Customer", "email": "siswa.baru@example.test", "activation_status": "pending" },
  "invitation": { "sent_to": "siswa.baru@example.test", "expires_at": "2026-11-03T02:00:00.000000Z", "mail_sent": true }
}
```

`POST /api/admin/customers/{id}/invitation` → **200**
`{message, data: {sent_to, expires_at, mail_sent}}`. `409 already_activated`,
`422 email_missing`, `404` for closed accounts. Rate limited.

`PATCH /api/admin/customers/{id}` with `email`: allowed while pending (revokes links sent
to the old address; send a new invitation afterwards). After activation:
`422 errors.email = "Email tidak dapat diubah setelah rekening diaktifkan."`

### 3.7 Rate limits

| Limiter | Endpoints | Limit |
| --- | --- | --- |
| `login` | `/login`, `/customer/login`, `/customer/auth/login`, `/customer/activation/resend` | 5/min per identifier + IP; with only `id_token`: 30/min per IP |
| `customer-activation` | `/customer/activation/inspect`, `/complete` | 20/min per IP |
| `customer-activation-resend` | `/customer/activation/resend` | 3/hour per identifier (on top of `login`) |
| `customer-invitation` | `/admin/customers/{id}/invitation` | 5/hour per customer |
| `customer-unlock` | `/customer/unlock` | 5/min per customer |

The limits for Firebase logins and activation are per IP and generous because a class may
share one school Wi-Fi address; ID tokens and activation tokens cannot be guessed.

## 4. Lifecycle

```text
                admin creates (email required)            customer completes activation
  [pending] ──────────────────────────────▶ invitation ──────────────────────────────────▶ [activated]
     │  ▲   admin resend / customer resend (new link, old revoked)                        │
     │  └──────────────────────────────────────┘                                          │
     │ admin changes email: outstanding links revoked                                     │
     ▼                                                                                    ▼
  admin deactivates: account inactive + soft deleted, tokens and links revoked, Firebase user disabled
```

- Pending accounts bank normally at the counter (deposits, Mode B withdrawals when
  enabled). Mode A (approval in the app) needs an activated app.
- There is no reactivation endpoint. A closed account cannot be invited or activated,
  and its Firebase identity cannot log in even if it is re-enabled in the Firebase console,
  because the backend checks the account first.

## 5. Firebase revocation and `checkRevoked`

`KreaitFirebaseAuth::verifyIdToken()` verifies the signature and claims with the SDK,
re-checks audience and issuer against `FIREBASE_PROJECT_ID`, checks the provider, then
loads the Firebase user once to refuse **disabled** users and tokens whose `auth_time` is
before the user's `tokensValidAfterTime` (the SDK's `checkIfRevoked` rule; the SDK's own
flag does not check "disabled", so the adapter does both with one lookup).

This runs at **login and activation**, the only places a backend session is created. It is
not run per banking request: those use the Sanctum token, which deactivation deletes and
`logout`/`logout-all` revoke. Consequence: revoking a user in the Firebase console (or a
password change, which revokes other Firebase sessions) does not end existing backend
sessions; use `logout-all` or deactivation for that.

## 6. Firebase Console configuration

The project in use is `bank-mini-rumput`; what is already configured there, and the web
config for the customer app, is in [firebase-web-config.md](firebase-web-config.md).

1. **Project.** Create or choose a Firebase project. Its ID is `FIREBASE_PROJECT_ID`.
2. **Sign-in providers** (Authentication → Sign-in method):
   - Enable **Email/Password** (the "Email link (passwordless)" option is not used).
   - Enable **Google** and set the project support email.
   - Leave other providers disabled (the backend refuses them anyway, see
     `FIREBASE_ALLOWED_PROVIDERS`).
3. **One account per email** (Authentication → Settings → User account linking): keep the
   default "Link accounts that use the same email". The backend never links accounts by
   email; the client must sign in with the existing method and call `linkWithCredential`
   (see FRONTEND_HANDOFF.md §15.10).
4. **Email enumeration protection** (Authentication → Settings → User actions): keep it
   **enabled** (default for projects created since September 2023).
5. **Authorized domains** (Authentication → Settings): add the domain that serves the
   activation page and `localhost` for development.
6. **Email templates** (Authentication → Templates): set the template language to
   Indonesian for email verification and password reset, and the sender name. Firebase sends
   these emails itself; Laravel's SMTP is not involved unless you configure custom SMTP in
   the Firebase console.
7. **Password policy** (optional, Authentication → Settings): minimum length and
   character requirements.
8. **Apps:** register the client apps (Project settings → General). A web app gives the
   web config (`apiKey`, `authDomain`, `projectId`, `appId`); Android and iOS apps are
   needed for native Google Sign-In (Android: add the SHA-1 and SHA-256 fingerprints of the
   signing keys; iOS: the reversed client ID URL scheme).
9. **Google OAuth consent screen** (Google Cloud console → APIs & Services → OAuth consent
   screen): app name, support email, authorized domains and, for production, publishing the
   app. Native Google Sign-In libraries need the **web client ID** of the project.
10. **Service account** (Project settings → Service accounts → Generate new private key):
    save the JSON on the server outside the web root, for example
    `storage/app/private/firebase-credentials.json` (git-ignored), and set
    `FIREBASE_CREDENTIALS` to its path. Treat it like a database password. Rotate it by
    generating a new key and deleting the old one.

## 7. SMTP configuration

Laravel sends the invitation and "account activated" emails through its mail
configuration (`config/mail.php`). The default `.env` uses `MAIL_MAILER=log`, which writes
emails to `storage/logs/laravel.log` (handy for development: the activation link is in the
log).

For real delivery set, in `.env` (never commit real values):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null          # "smtps" for implicit TLS on port 465; null for STARTTLS on 587
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=bank-mini@example.sch.id
MAIL_PASSWORD=            # SMTP password or app password
MAIL_FROM_ADDRESS="bank-mini@example.sch.id"
MAIL_FROM_NAME="${BANK_NAME}"
```

Gmail / Google Workspace needs an app password (2-step verification on) or SMTP relay;
for testing, a sandbox such as Mailtrap catches all mail. Check the configuration with:

```bash
php artisan tinker --execute="Mail::raw('Tes SMTP', fn (\$m) => \$m->to('you@example.com'));"
```

Templates: `resources/views/mail/customer-invitation(.blade.php|-text.blade.php)`,
`customer-activated(…)`, layout `resources/views/components/mail-layout.blade.php`
(table layout with inline styles, 560 px max width, plain-text alternative). The bank name
comes from `BANK_NAME`, the school from `BANK_SCHOOL`. Firebase's own emails (verification,
password reset) use the Firebase templates (§6.6).

## 8. Environment variables

```dotenv
# Customer app sessions (minutes; default 30 days). Staff: SANCTUM_EXPIRATION (480).
CUSTOMER_SESSION_MINUTES=43200

# Activation links: "{CUSTOMER_ACTIVATION_URL}?token=..." valid for N hours.
CUSTOMER_ACTIVATION_URL=https://app.example.sch.id/aktivasi
CUSTOMER_ACTIVATION_TOKEN_HOURS=24

# Firebase (server side)
FIREBASE_PROJECT_ID=bank-mini-sekolah
FIREBASE_CREDENTIALS=storage/app/private/firebase-credentials.json
FIREBASE_ALLOWED_PROVIDERS=password,google.com
# FIREBASE_AUTH_EMULATOR_HOST=127.0.0.1:9099   # local emulator only

# Mail: see §7
```

`CUSTOMER_ACTIVATION_URL` must point at the customer app's activation page (a web route,
or a universal/app link that opens the mobile app). The default
`http://localhost:8081/aktivasi` is a placeholder: no customer app exists yet.

Client apps need the Firebase **web config** (not secret) through their own environment,
for example `VITE_FIREBASE_API_KEY`, `VITE_FIREBASE_AUTH_DOMAIN`,
`VITE_FIREBASE_PROJECT_ID`, `VITE_FIREBASE_APP_ID` (Vite) or `EXPO_PUBLIC_FIREBASE_*`
(Expo), plus the Google web client ID for native Google Sign-In.

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

The client SDK connects with `connectAuthEmulator(auth, "http://127.0.0.1:9099")`.
The emulator accepts fake Google sign-ins and lets you mark emails verified. The full flow
(Google and email/password activation, login, revocation, deactivation disabling the
Firebase user) was verified against the emulator this way.

## 10. Migration instructions

1. `composer install` (adds `kreait/firebase-php`).
2. `php artisan migrate` — adds the four nullable columns and the token table. Existing
   rows, balances and history are untouched; existing customers become `pending` with no
   email. Rollback (`migrate:rollback --step=2`) removes them again.
3. Configure Firebase (§6), SMTP (§7) and the environment (§8).
4. For each existing customer: the administrator adds the email
   (`PATCH /api/admin/customers/{id}` `{email}`) and sends the invitation
   (`POST /api/admin/customers/{id}/invitation`).
5. Tell students that NIS + PIN no longer signs in: they activate from the email, then
   sign in with Google or email/password; the PIN stays for unlocking and withdrawals.
6. Customer tokens issued before the upgrade stay valid until their 8-hour expiry.
7. `php artisan migrate:fresh --seed` (development) creates demo customers with
   `@example.test` emails, pending; no invitation is sent by the seeder.

## 11. Security considerations

- **Two proofs to claim an account:** the invitation token (possession of the registered
  mailbox) and a Firebase ID token with that same, verified email. Neither alone works; an
  email match alone never links an identity.
- **Single use, short-lived, hashed links:** 256-bit tokens, SHA-256 hash stored, 24 h,
  newest-only, revoked on email change and deactivation.
- **No enumeration:** activation and login codes describe only the caller's own token or
  identity. NIS + PIN responses reveal activation state only after a correct PIN. Admin
  validation (`email` unique) is visible to administrators only. Keep Firebase email
  enumeration protection on.
- **PIN brute force:** unchanged shared lock (3 wrong → 15 min) across NIS check, unlock,
  resend, withdrawal approval, terminal withdrawal and PIN change, plus rate limits.
- **Long sessions:** a stolen session token works until it expires (30 days by default) or
  is revoked. Mitigations: tokens in secure storage, PIN unlock in the app, `logout-all`,
  deactivation. Consider a shorter `CUSTOMER_SESSION_MINUTES` if devices are shared.
- **PIN unlock is a client-side gate:** the server verifies the PIN, but the token is
  accepted by the API without a recent unlock. It protects a lost unlocked phone's UI, not a
  copied token.
- **Secrets:** service account JSON and SMTP password only in server environment / files
  outside the repository (`.gitignore` covers `*firebase-adminsdk*.json` and
  `firebase-credentials.json`). The Firebase web config is public by design.
- **Firebase outages** return `503 auth_unavailable` for login/activation; banking with an
  existing session and counter operations keep working. Deactivation succeeds and logs a
  warning if the Firebase user cannot be disabled.
- **Not covered:** multi-factor authentication, device binding, revoking backend sessions
  automatically when Firebase sessions are revoked (§5), push notifications.
