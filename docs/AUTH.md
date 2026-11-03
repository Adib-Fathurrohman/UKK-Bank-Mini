# Authentication

How the backend authenticates both clients. Source: `routes/api.php`,
`app/Http/Controllers/API/LoginController.php`, `Staff/AuthController.php`,
`Customer/AuthController.php`, `Customer/ActivationController.php`,
`app/Services/CustomerAuthService.php`, `app/Http/Requests/Auth/*`,
`app/Providers/AppServiceProvider.php`, `config/sanctum.php`, `config/bank.php`,
`bootstrap/app.php`.

Customers sign in with their **NIS or account number + PIN**. New accounts get a
temporary PIN that must be replaced at the first login. The full design (and the FCM push
notifications) is in [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md); this page summarises how tokens
work for both actors.

## 1. Mechanism

- **Laravel Sanctum personal access tokens** (bearer tokens). One token per login,
  stored hashed in `personal_access_tokens`; the plain token is returned only once,
  in the login response.
- **Two token owners**: staff (`App\Models\User`, token name `staff-portal`) and
  customers (`App\Models\Customer`, token name `customer-portal`). Customer tokens are
  issued after a correct NIS/account number + PIN. Both are checked by
  the same `auth:sanctum` guard; the `staff` / `customer` middleware then rejects the
  wrong kind with `403`.
- **No cookies or sessions** are used by the API. Sanctum's stateful SPA mode is not
  enabled for the API routes, so cookie-based SPA authentication and CSRF tokens do not
  apply. Use the `Authorization` header from both clients.
- CORS (framework default): all origins, all methods and headers, **no credentials**,
  no exposed response headers. Fine for bearer tokens.

## 2. Login

`POST /api/login` is the single gateway for all four roles. The body decides which
login runs:

| Body | Who | Result |
| --- | --- | --- |
| `{"username": "...", "password": "..."}` | Admin, supervisor, teller | Staff token. username required (max 255), password required; password must match (bcrypt); account must not be blocked (`is_active`) |
| `{"identifier": "...", "pin": "......"}` | Customer | Customer token. identifier: NIS or account number (max 50); PIN exactly 6 digits; checked with the shared PIN lock (same as `POST /api/customer/login`) |

If `username` is filled the staff login runs; otherwise if `identifier` is filled the
customer login runs; otherwise `422` with an error on `username`.

`POST /api/staff/login` and `POST /api/customer/login` are aliases that accept only their
own body.

**Staff success (200):**

```json
{
  "data": {
    "actor": "staff",
    "token": "3|FsAYFafTXVEvVS53PFOaEpdq9AdSeFJ1grmZ2TBS63522aba",
    "token_type": "Bearer",
    "user": { "id": 3, "username": "teller", "full_name": "Teller Loket 1", "class": "XI AK 1", "phone": "081200000001", "role": "teller", "is_active": true, "created_at": "...", "updated_at": "..." }
  }
}
```

**Customer success (200):** `actor` is `"customer"`, the profile is in `data.customer`
(see [DATA_MODEL.md](DATA_MODEL.md#customer-customers)), `data.expires_at` gives the
session end and `data.must_change_pin` is `true` while the customer has the temporary PIN.
Such a session can only call `GET /customer/me`, `POST /customer/pin` and the logouts;
everything else answers `428 {"code": "pin_change_required"}`.

**Failures (422)**, error key `username` (staff) or `identifier` (customer):

| Message | Cause |
| --- | --- |
| `Username atau password salah.` | Unknown username or wrong password |
| `Akun staf ini sudah dinonaktifkan.` | Correct password but blocked staff |
| `NIS/nomor rekening atau PIN salah.` | Unknown identifier, wrong PIN, or closed (soft-deleted) account. The remaining attempts are not revealed |
| `Terlalu banyak percobaan PIN salah. Coba lagi pukul HH:MM.` | The PIN is locked (3 wrong PINs in a row, at login or anywhere else). Local time |
| `Rekening ini sudah tidak aktif.` | `inactive` account that is not soft-deleted |
| `Isi username dan password (staf), atau NIS/nomor rekening dan PIN (nasabah).` | No credential set sent |
| Laravel field messages | Missing fields, PIN not 6 digits |

**Rate limit:** 5 attempts per minute per (lowercased username or identifier + IP). The
next one returns `429 {"message":"Too Many Attempts."}`. Suspended customers **can** log
in.

**PIN:** the system generates a temporary PIN for a new account and for an admin reset;
the customer replaces it with `POST /customer/pin`. A temporary PIN never authorizes a
withdrawal (`422 pin_change_required`). One counter covers login, the app unlock,
withdrawal approval, terminal withdrawals and `POST /customer/pin`: three wrong PINs in a
row (anywhere) lock the PIN for 15 minutes, and during the lock even the correct PIN is
refused. NIS and account number lead to the same counter. See
[PIN_VERIFICATION.md](PIN_VERIFICATION.md#2-pin-storage-and-the-shared-lock).

## 3. Using the token

```http
Authorization: Bearer 3|FsAYFafTXVEvVS53PFOaEpdq9AdSeFJ1grmZ2TBS63522aba
Accept: application/json
```

On every request the token owner is loaded fresh from the database, so blocking a
staff member, changing a role or closing an account takes effect immediately.

## 4. Current user

| Actor | Endpoint | Response |
| --- | --- | --- |
| Staff | `GET /api/staff/me` | `{ "data": Staff }` |
| Customer | `GET /api/customer/me` | `{ "data": Customer }` (with major) |

Call it on app start to validate a stored token and to get the role. A `403` means the
token belongs to the other kind of actor or the account was blocked/closed.

## 5. Logout

| Actor | Endpoint | Response |
| --- | --- | --- |
| Staff | `POST /api/staff/logout` | `200 {"message":"Berhasil keluar."}` |
| Customer | `POST /api/customer/logout` | `200 {"message":"Berhasil keluar."}` |
| Customer, all devices | `POST /api/customer/logout-all` | `200 {"message":"Berhasil keluar dari semua perangkat."}` |

`logout` revokes only the token used for the request; other devices stay logged in.
Always delete the stored token on the client, even if the request fails. A customer
logout also removes the push token the phone registered with that session.

Tokens are also revoked server-side when an admin blocks a staff member, deletes a
staff member, deactivates (graduates) a customer, or resets a customer's PIN.

## 6. Expiration and refresh

- **Staff** tokens expire **480 minutes (8 hours) after creation** (`SANCTUM_EXPIRATION`).
- **Customer** tokens expire `CUSTOMER_SESSION_MINUTES` after login
  (default 43200 = 30 days). The login response includes `expires_at`. Customer tokens
  issued before this change (without `expires_at`) keep the 8-hour rule.
- The expiry is absolute; activity does not extend it.
- **There is no refresh endpoint.** An expired token returns `401`: log in again.
- Inside a customer session the app unlocks with the PIN (`POST /api/customer/unlock`).
- Expired token rows are pruned daily by the scheduler (`sanctum:prune-expired`), if the
  scheduler is running.

## 7. Unauthorized responses

| Status | Body | Meaning | Client action |
| --- | --- | --- | --- |
| `401` | `{"message":"Unauthenticated."}` | Missing, invalid, revoked or expired token | Delete token, show login |
| `403` | `{"message":"Fitur ini khusus untuk staf bank."}` | Customer token on a staff endpoint | Delete token, show login |
| `403` | `{"message":"Fitur ini khusus untuk nasabah."}` | Staff token on a customer endpoint | Delete token, show login |
| `403` | `{"message":"Akun staf ini sudah dinonaktifkan."}` | Staff blocked while logged in | Delete token, show login with the message |
| `403` | `{"message":"Rekening ini sudah tidak aktif."}` | Customer account closed | Delete token, show login |
| `403` | `{"message":"Peran Anda tidak memiliki akses ke fitur ini."}` | Wrong staff role for the endpoint | Return to login (rubric 3.2) |

API routes never redirect; guests always get JSON `401`.

## 8. Not implemented

- Token refresh / sliding sessions.
- "Remember me" options.
- Self-service forgot-PIN (an administrator reset gives a new temporary PIN).
- Staff password reset or changing their own password (an admin can set passwords of
  tellers and supervisors; admin passwords cannot be changed through the API).
- Listing a user's sessions/devices (customers can revoke all with `logout-all`).
- Two-factor authentication.

## 9. Storage recommendations

### React web app (staff)

- Keep the token in memory for the session and persist it in `sessionStorage` (cleared
  when the tab closes) or `localStorage` (survives browser restarts). The token gives
  full staff access for up to 8 hours; `localStorage`/`sessionStorage` are readable by
  any script on the page, so avoid third-party scripts and never render untrusted HTML.
- Store `actor` and `user.role` alongside the token for routing, but re-validate with
  `GET /api/staff/me` on load.
- Use one HTTP client (e.g. a fetch wrapper) that adds the header and handles
  `401`/`403` globally.
- Shared counter computers: always log out at the end of a shift; consider
  `sessionStorage` so closing the browser ends the session.

### React Native + Expo app (customers)

- Store the token and `expires_at` with **`expo-secure-store`** (Keychain / Android
  Keystore), not `AsyncStorage`.
- On app start: read the token → if it has not expired, ask for the PIN
  (`POST /api/customer/unlock`; on `428 pin_change_required` show the PIN change) →
  `GET /api/customer/me`; on `401`/`403` delete it and show the login screen.
- The base URL must be reachable from the phone (LAN IP or deployed URL, see
  [FRONTEND_HANDOFF.md](FRONTEND_HANDOFF.md#3-api-base-url-configuration)). Plain
  `http://` to a LAN IP may need cleartext traffic allowed in development builds on
  Android.
- PIN input: numeric keypad, masked, 6 digits. Do not store the PIN. The same rules
  apply to the PIN pad used to approve a teller's withdrawal request.
- On the PIN screens, show the lock message as returned (it contains the unlock time).
- The login rate limit is per identifier + IP, so several phones behind one school Wi-Fi
  do not block each other.
- Push notifications (FCM) and the forced PIN change: see
  [FRONTEND_NIS_PIN_FCM.md](FRONTEND_NIS_PIN_FCM.md).
