# E-Teller Bank Mini — API Reference

REST API for the school mini bank. One login gateway serves four roles:
Administrator, Supervisor and Teller (staff web app) and Nasabah (customer
mobile app). Examples are real responses captured from the seeded demo
database (long lists shortened), except the customer login and activation examples,
which are illustrative (they need a Firebase project). Customer login, activation,
Firebase setup and the customer PIN: [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md).

## Contents

- [Conventions](#conventions)
- [Authentication](#authentication)
- [Errors](#errors)
- [Pagination](#pagination)
- [Access matrix](#access-matrix)
- [Endpoint summary](#endpoint-summary)
- [Endpoints](#endpoints)
  - [Login gateway](#login-gateway)
  - [Customer login and activation](#customer-login-and-activation)
  - [Shared staff endpoints](#shared-staff-endpoints)
  - [Staff management](#staff-management)
  - [Settings](#settings)
  - [Customer accounts](#customer-accounts)
  - [Teller: shift and cash closing](#teller-shift-and-cash-closing)
  - [Teller: transactions](#teller-transactions)
  - [Teller: corrections](#teller-corrections)
  - [Withdrawal authorizations (mobile approval)](#withdrawal-authorizations-mobile-approval)
  - [Supervisor: daily reports](#supervisor-daily-reports)
  - [Supervisor: vault ledger](#supervisor-vault-ledger)
  - [Audit](#audit)
  - [Reports and PDF exports](#reports-and-pdf-exports)
  - [Customer portal](#customer-portal)
- [Objects](#objects)
- [Workflows](#workflows)

---

## Conventions

| Item | Rule |
| --- | --- |
| Base URL | `http://localhost:8000/api` (the app's host plus `/api`) |
| Headers | `Accept: application/json` always; `Content-Type: application/json` with a body; `Authorization: Bearer <token>` everywhere except login |
| Money | Integer Rupiah, no decimals (`50000` = Rp50.000) |
| Timestamps | ISO 8601 in UTC, e.g. `2026-10-27T13:10:36.000000Z` (= 20:10 WIB). Convert to local time in the client |
| Dates | `YYYY-MM-DD` in the school's timezone (Asia/Jakarta). Audit filters also accept `YYYY-MM-DD HH:MM` |
| Single objects | Wrapped in `data` |
| Lists | `data` array plus `links` and `meta` |
| PDFs | `Content-Type: application/pdf` and `Content-Disposition: attachment; filename=...` |
| Messages | Business-rule messages are Indonesian; field validation messages are Laravel's English defaults |

### Enums

| Field | Values |
| --- | --- |
| Staff `role` | `admin`, `supervisor`, `teller` |
| Customer `account_status` | `active` (can transact), `suspended` (can log in and view, cannot transact), `inactive` (graduated: cannot log in, soft deleted) |
| Customer `class` / `group` | `X`, `XI`, `XII` / `1`–`4` |
| Transaction `type` | `deposit`, `withdrawal`, `deposit_correction`, `withdrawal_correction` |
| Journal `account_code` | `101` (Kas), `201` (Tabungan Nasabah) |
| Journal `position` | `debit`, `credit` |
| Daily report `status` | `open`, `submitted`, `on_hold`, `approved`, `rejected` |
| Setting `qr_scanner_mode` | `webcam`, `usb`, `disabled` |
| Setting `withdrawal_verification` | `mobile`, `terminal`, `both` |
| Withdrawal authorization `status` | `pending`, `approved`, `consumed` (done), `rejected`, `cancelled`, `expired` |
| Cash denominations | `100000`, `50000`, `20000`, `10000`, `5000`, `2000`, `1000`, `500`, `200`, `100` |

### Account numbers

`{major code}-{school year}-{serial}`, e.g. `RPL-2026-001`. The school year runs
July–June (an account opened in October 2026 or June 2027 gets `2026`). The serial
restarts for every major and school year and is never reused. Major codes: AK, BR,
BD, MP, DKV, RPL, TKJ, LPS.

---

## Authentication

Laravel Sanctum bearer tokens, issued by the single login gateway `POST /login`
(staff: username + password; customers: a Firebase ID token). Staff tokens only work on
staff endpoints, customer tokens only on `/customer/*`.

- Staff tokens expire after **8 hours** (`SANCTUM_EXPIRATION`); customer tokens after
  `CUSTOMER_SESSION_MINUTES` (default **30 days**, returned as `expires_at`). An expired
  token returns `401`.
- Logout revokes only the token used for that request (`/customer/logout-all` revokes
  all of a customer's tokens).
- Staff login is limited to **5 attempts per minute** per username and IP, and
  Firebase logins to 30 per minute per IP (`429`).
- The PIN is not a login credential. The customer creates it in the app after activation;
  it is checked with one counter for withdrawal approval, terminal withdrawals, the app
  unlock and PIN change: **3 wrong PINs in a row lock the PIN for 15 minutes** everywhere.
- Blocking a staff member or closing a customer account revokes their tokens immediately.
- Every request re-checks the actor and role against the database before running a query.

```http
POST /api/login
Accept: application/json
Content-Type: application/json

{ "username": "teller", "password": "password" }
```

Use `data.token` from the response on later requests:

```http
GET /api/teller/shift
Accept: application/json
Authorization: Bearer 3|FsAYFafTXVEvVS53PFOaEpdq9AdSeFJ1grmZ2TBS63522aba
```

---

## Errors

Every error has a `message`. Validation errors also have `errors` keyed by field.
Some business errors add extra fields (shown below).

| Status | When | Example body |
| --- | --- | --- |
| `401` | Missing, invalid or expired token | `{ "message": "Unauthenticated." }` |
| `401`, `403`, `409`, `503` | Customer login, activation or PIN setup refusal, with a `code` (see [Customer login and activation](#customer-login-and-activation)) | `{ "message": "NIS tidak cocok dengan email akun ini. ...", "code": "activation_mismatch" }` |
| `403` | Wrong actor or role, or a blocked/closed account | `{ "message": "Peran Anda tidak memiliki akses ke fitur ini." }` |
| `404` | Record does not exist or belongs to someone else | `{ "message": "Data tidak ditemukan." }` |
| `405` | Method not allowed on that URL (e.g. writing to the journal) | `{ "message": "The DELETE method is not supported for route ..." }` |
| `409` | Staff delete refused because of history | `{ "message": "Staf ini memiliki riwayat transaksi, ... Blokir akunnya saja." }` |
| `422` | Field validation failed | `{ "message": "...", "errors": { "amount": ["The amount field must be at least 1."] } }` |
| `422` | Business rule refused the request | `{ "message": "Saldo tidak mencukupi. Saldo minimum Rp10.000 harus tetap tersisa; penarikan maksimal Rp990.000." }` |
| `422` with `code` | `pin_not_set` (the customer has not created a PIN yet) or `customer_app_not_activated` | `{ "message": "Nasabah belum membuat PIN. ...", "code": "pin_not_set" }` |
| `429` | Too many login, activation, unlock, PIN setup, correction or withdrawal-authorization requests | `{ "message": "Too Many Attempts." }` |
| `500` | Unexpected server error; any money operation is fully rolled back | `{ "message": "Server Error" }` |

`403` messages tell the client what failed: `Fitur ini khusus untuk staf bank.`
(customer token on a staff URL), `Fitur ini khusus untuk nasabah.` (staff token on a
customer URL), `Peran Anda tidak memiliki akses ke fitur ini.` (wrong staff role),
`Akun staf ini sudah dinonaktifkan.`, `Rekening ini sudah tidak aktif.`

The role check runs before the record is looked up, so a wrong role always gets
`403`, never `404`. **Frontends should log the user out and return to the login
page on `401` or `403`** (rubric 3.2).

---

## Pagination

Lists accept `?page=` (default 1) and `?per_page=` (1–100; default per endpoint).

```json
{
  "data": [ ... ],
  "links": { "first": "...?page=1", "last": "...?page=2", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 2, "path": "...", "per_page": 15, "to": 15, "total": 22 }
}
```

---

## Access matrix

Matches the matrix in the UKK soal (section III.1).

| Feature | Admin | Teller | Supervisor | Nasabah |
| --- | :-: | :-: | :-: | :-: |
| User management | C, R, U, D | — | R | — |
| Accounts (open, edit, close, print card, reset PIN) | C, R, U | R (lookup) | R | — |
| App activation, sign-in (Firebase) and creating the PIN | — | — | — | own account (NIS + registered email) |
| QR scan lookup | Configure (settings) | R (execute) | — | — |
| Deposit / withdrawal / correction | R (audit log) | C | R (audit log) | — |
| Withdrawal PIN | — | C (request), U (complete) | — | U (approve/reject own request with PIN in the app) |
| Shift, cash closing, daily report draft | — | C, R | R | — |
| Approve / hold / reject daily report | — | — | U | — |
| Cash to vault (on approval) | — | — | U | — |
| Own balance, statement, card, PIN change | — | — | — | R (own data) |
| Accounting journal and reports | R | — | R | — |

---

## Endpoint summary

| Method | Path | Who |
| --- | --- | --- |
| `POST` | `/login` | Public (all roles) |
| `POST` | `/staff/login`, `/customer/auth/login` | Public (aliases of `/login`) |
| `POST` | `/customer/activation` | Public (NIS + Firebase ID token) |
| `GET` | `/staff/me` · `POST` `/staff/logout` | Staff |
| `GET` | `/majors` · `/settings` | Staff |
| `GET` | `/staff`, `/staff/{id}` | Admin, Supervisor |
| `POST` | `/admin/staff`, `/admin/staff/import` | Admin |
| `PUT`/`PATCH`, `DELETE` | `/admin/staff/{id}` | Admin |
| `POST` | `/admin/staff/{id}/block`, `/admin/staff/{id}/unblock` | Admin |
| `PUT`/`PATCH` | `/admin/settings` | Admin |
| `GET` | `/customers`, `/customers/{id}` | Admin, Supervisor |
| `GET` | `/customers/{id}/statement` (PDF) | Admin, Supervisor |
| `POST` | `/admin/customers` | Admin |
| `PUT`/`PATCH` | `/admin/customers/{id}` | Admin |
| `POST` | `/admin/customers/{id}/deactivate`, `/admin/customers/{id}/pin-reset` | Admin |
| `GET` | `/admin/customers/{id}/card` | Admin |
| `GET` | `/teller/shift` · `POST` `/teller/shift/open`, `/teller/shift/close` | Teller |
| `GET` | `/teller/daily-reports`, `/teller/daily-reports/{id}`, `/teller/daily-reports/{id}/export` (PDF) | Teller |
| `GET` | `/teller/customers/lookup` | Teller |
| `POST` | `/teller/deposits`, `/teller/withdrawals` | Teller |
| `GET` | `/teller/withdrawal-authorizations`, `/teller/withdrawal-authorizations/{id}` | Teller |
| `POST` | `/teller/withdrawal-authorizations`, `/teller/withdrawal-authorizations/{id}/cancel`, `/complete` | Teller |
| `GET` | `/teller/transactions`, `/teller/transactions/{id}/receipt` (PDF) | Teller |
| `POST` | `/teller/transactions/{id}/correct` | Teller |
| `GET` | `/supervisor/daily-reports`, `/supervisor/daily-reports/{id}`, `/supervisor/daily-reports/{id}/export` (PDF) | Supervisor |
| `POST` | `/supervisor/daily-reports/{id}/approve`, `/hold`, `/reject` | Supervisor |
| `GET` | `/supervisor/cash-transfers`, `/supervisor/cash-transfers/{id}` | Supervisor |
| `GET` | `/audit/transactions`, `/audit/transactions/{id}`, `/audit/transactions/export` (PDF) | Admin, Supervisor |
| `GET` | `/audit/journals`, `/audit/journals/summary`, `/audit/journals/export` (PDF) | Admin, Supervisor |
| `GET` | `/reports/annual` (PDF) | Admin, Supervisor |
| `GET` | `/customer/me`, `/customer/balance`, `/customer/card` | Nasabah |
| `GET` | `/customer/transactions`, `/customer/transactions/{id}`, `/customer/statement/export` (PDF) | Nasabah |
| `POST` | `/customer/pin/setup` · `/customer/pin` · `/customer/unlock` · `/customer/logout` · `/customer/logout-all` | Nasabah |
| `POST`, `DELETE` | `/customer/push-tokens` | Nasabah |
| `GET` | `/customer/withdrawal-authorizations`, `/customer/withdrawal-authorizations/{id}` | Nasabah |
| `POST` | `/customer/withdrawal-authorizations/{id}/approve`, `/reject` | Nasabah |

---

## Endpoints

### Login gateway

#### `POST /login`

The single entry point for all four roles (soal: "satu pintu gerbang login terpusat").
Send staff credentials or a customer's Firebase ID token. `data.actor` tells the client
which portal to open. `/staff/login` and `/customer/auth/login` accept the same bodies.

| Field | Rules |
| --- | --- |
| `username`, `password` | Staff: required together |
| `id_token` | Customer: Firebase ID token (see [Customer login and activation](#customer-login-and-activation)) |

**200 (staff)**

```json
{
  "data": {
    "actor": "staff",
    "token": "3|FsAYFafTXVEvVS53PFOaEpdq9AdSeFJ1grmZ2TBS63522aba",
    "token_type": "Bearer",
    "user": {
      "id": 3, "username": "teller", "full_name": "Teller Loket 1",
      "class": "XI AK 1", "phone": "081200000001", "role": "teller", "is_active": true,
      "created_at": "2026-10-27T13:10:33.000000Z", "updated_at": "2026-10-27T13:10:33.000000Z"
    }
  }
}
```

**200 (customer, `id_token`)**: `actor` is `"customer"`, `data.customer` is a
[Customer](#customer) and `data.expires_at` is the session end.

**422**: wrong staff credentials (`Username atau password salah.`), blocked staff, or no
credentials (NIS/account number + PIN are not credentials and get this answer too):

```json
{
  "message": "Isi username dan password (staf), atau masuk dengan akun Google/email (nasabah).",
  "errors": { "username": ["Isi username dan password (staf), atau masuk dengan akun Google/email (nasabah)."] }
}
```

---

### Customer login and activation

Customers sign in with **Firebase Authentication** (Google or email/password) and exchange
the Firebase ID token for a Sanctum token. The first time, they activate the account with
their NIS, then create their PIN. The backend sends no email. Design, error handling and
setup: [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md); client flow:
[FRONTEND_HANDOFF.md §15.10](FRONTEND_HANDOFF.md#1510-customer-activation-pin-login-unlock-and-password-firebase).
The examples in this section are illustrative.

Refusals have the shape `{ "message": "...", "code": "..." }`:

| `code` | Status | Endpoints |
| --- | --- | --- |
| `firebase_token_invalid` | 401 | auth/login, activation, pin/setup |
| `provider_not_allowed` | 403 | auth/login, activation, pin/setup |
| `account_not_linked` | 403 | auth/login (first sign-in: activate) |
| `email_not_verified` | 403 | auth/login, activation |
| `account_inactive` | 403 | auth/login, activation |
| `activation_mismatch` | 403 | activation |
| `already_activated` | 409 | activation |
| `identity_already_linked` | 409 | activation |
| `identity_mismatch` | 403 | pin/setup |
| `pin_already_set` | 409 | pin/setup |
| `auth_unavailable` | 503 | auth/login, activation, pin/setup |

#### `POST /customer/auth/login`

**Public.** `{ "id_token": "<Firebase ID token>" }` (max 4096 characters).

**200**

```json
{
  "data": {
    "actor": "customer",
    "token": "12|5pJf0b2hQx...",
    "token_type": "Bearer",
    "expires_at": "2026-12-02T02:00:00.000000Z",
    "customer": {
      "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso",
      "email": "budi.santoso@example.test", "activation_status": "activated", "activated_at": "2026-11-02T02:00:00.000000Z",
      "pin_set": true, "class": "X", "group": "1",
      "major": { "id": 1, "code": "RPL", "major_name": "Rekayasa Perangkat Lunak" },
      "account_status": "active", "balance": 500000,
      "created_at": "2026-10-27T13:10:35.000000Z", "updated_at": "2026-11-02T02:00:00.000000Z", "deleted_at": null
    }
  }
}
```

The customer is the one bound to the token's Firebase UID at activation; emails and ids in
the body are never used to pick the account. Errors: `401 firebase_token_invalid`,
`403 provider_not_allowed | account_not_linked | email_not_verified | account_inactive`,
`503 auth_unavailable`, `422`, `429`. Suspended accounts may log in. `pin_set: false` →
the app shows PIN creation.

#### `POST /customer/activation`

**Public.** `{ "nis": "20260001", "id_token": "<Firebase ID token>" }`. Binds the Firebase
account to the customer with this NIS when the token's **verified** email equals the
registered email (case-insensitive), and returns **200** with the same body as
`/customer/auth/login` (`pin_set: false`). With the same Firebase account again it just
signs in.

| Status | `code` | Cause |
| --- | --- | --- |
| 403 | `email_not_verified` | Email/password account not verified yet (Firebase sends the verification email) |
| 403 | `activation_mismatch` | `NIS tidak cocok dengan email akun ini. Masuk dengan email yang didaftarkan di bank mini, atau hubungi petugas.` One answer for an unknown NIS, another email or no registered email |
| 403 | `account_inactive` | Account closed |
| 409 | `already_activated` | Activated with a different Firebase account |
| 409 | `identity_already_linked` | This Firebase account is bound to another customer |
| 401 / 403 / 503 | `firebase_token_invalid` / `provider_not_allowed` / `auth_unavailable` | As for login |

20 requests per minute per IP.

#### `POST /customer/pin/setup`

**Nasabah.** Create the PIN after activation, or after an administrator reset.

| Field | Rules |
| --- | --- |
| `id_token` | required: a fresh Firebase ID token of the account bound to this customer |
| `pin` | required, exactly 6 digits, confirmed |
| `pin_confirmation` | must equal `pin` |

**201** `{ "message": "PIN berhasil dibuat." }`. `403 identity_mismatch` (another Firebase
account), `401 firebase_token_invalid`, `409 pin_already_set` (use `POST /customer/pin`),
`422` validation. 5 per minute.

Until a PIN exists, everything that needs it answers **422**:

```json
{ "message": "Nasabah belum membuat PIN. PIN dibuat oleh nasabah di aplikasi Bank Mini setelah akun diaktifkan.", "code": "pin_not_set" }
```

(`/customer/unlock`, `/customer/pin`, approving a withdrawal, `/teller/withdrawals`,
`/teller/withdrawal-authorizations`). No wrong attempt is counted.

---

#### `POST /customer/push-tokens` · `DELETE /customer/push-tokens`

**Nasabah.** The phone's Firebase Cloud Messaging token, so the backend can push new
withdrawal requests. Client setup: [FRONTEND_HANDOFF.md §15.11](FRONTEND_HANDOFF.md#1511-push-notifications-fcm).

| Field | Rules |
| --- | --- |
| `token` | required, string, max 255 (FCM registration token) |
| `platform` | `android` or `ios` (required for `POST`) |

`POST` → **201** (new) or **200** (already known) `{ "message": "Notifikasi aktif di perangkat ini." }`.
The token belongs to the current session: logout, logout-all, deactivation and session
expiry remove it. A token already registered by another customer (shared phone) moves to
the caller. `DELETE` with `{token}` → **200** `{ "message": "Notifikasi dimatikan di perangkat ini." }`
(only the caller's own tokens). 10 per minute.

When a teller creates a withdrawal request, each registered phone gets a push:
notification title `Permintaan penarikan`, body `Teller {name} meminta persetujuan
penarikan Rp50.000. Buka aplikasi untuk menyetujui atau menolak.`, data
`{ "type": "withdrawal_authorization", "id": "<request id>" }`. It is sent after the
teller's response; a failure only logs a warning. The app keeps polling as the fallback.

---

### Shared staff endpoints

| Endpoint | Description |
| --- | --- |
| `GET /staff/me` | The logged-in [Staff](#staff) |
| `POST /staff/logout` | Revokes the current token: `{ "message": "Berhasil keluar." }` |
| `GET /majors` | All majors with codes, not paginated: `{ "data": [{ "id": 4, "code": "AK", "major_name": "Akuntansi dan Keuangan Lembaga" }, ...] }` |
| `GET /settings` | Current [settings](#settings) (the teller screen needs the scanner mode and minimum balance) |

---

### Staff management

#### `GET /staff` · `GET /staff/{id}`

**Admin, Supervisor.** [Staff](#staff) sorted by name. Default `per_page` 15.

| Query | Description |
| --- | --- |
| `status` | `active` (default), `inactive`, `all` |
| `role` | `admin`, `supervisor`, `teller` |
| `search` | Username or full name |

#### `POST /admin/staff`

**Admin.** Create a teller or supervisor.

| Field | Rules |
| --- | --- |
| `username` | required, letters/numbers/`-`/`_`, max 50, unique |
| `full_name` | required, max 255 |
| `password` | required, min 8 |
| `role` | required, `teller` or `supervisor` (`admin` is refused) |
| `class` | optional, max 20 (e.g. `XI AK 2`) |
| `phone` | optional, digits, spaces, `+`, `-`, max 20 (WhatsApp number) |

**201**

```json
{
  "data": {
    "id": 4, "username": "rina", "full_name": "Rina Wulandari", "class": "XI AK 2",
    "phone": "081200000002", "role": "teller", "is_active": true,
    "created_at": "2026-10-27T13:10:36.000000Z", "updated_at": "2026-10-27T13:10:36.000000Z"
  }
}
```

#### `PUT|PATCH /admin/staff/{id}`

**Admin.** Any subset of `username`, `full_name`, `password`, `role` (`teller`/`supervisor`),
`class`, `phone`. **403** for administrator accounts:
`Akun administrator tidak dapat diubah melalui API.`

#### `POST /admin/staff/{id}/block` · `POST /admin/staff/{id}/unblock`

**Admin.** Block keeps the account (its history must keep pointing at it), sets
`is_active` to `false` and revokes its tokens. Unblock restores access. **200** the
updated [Staff](#staff). **403** for administrators.

#### `DELETE /admin/staff/{id}`

**Admin.** Permanently deletes an account that was never used. **200**
`{ "message": "Akun staf dihapus." }`. **409** when transactions, reports or approvals
reference it (protected by `ON DELETE RESTRICT`):

```json
{ "message": "Staf ini memiliki riwayat transaksi, laporan, atau persetujuan sehingga tidak dapat dihapus. Blokir akunnya saja." }
```

#### `POST /admin/staff/import`

**Admin.** Create many tellers/supervisors from a spreadsheet. `multipart/form-data`
with field `file` (`.xlsx` or `.csv`, comma or semicolon separated, max 2 MB, max
500 rows). The first row is the header; Indonesian headers are accepted:

| Field | Accepted headers |
| --- | --- |
| username (required) | `username` |
| full name (required) | `full_name`, `nama`, `nama_lengkap` |
| password (optional, min 8) | `password`, `kata_sandi` |
| role (optional, default `teller`) | `role`, `peran` |
| class (optional) | `class`, `kelas` |
| phone (optional) | `phone`, `no_wa`, `whatsapp`, `no_hp` |

All or nothing: if any row is invalid, no account is created. Rows without a
password get a random 10-character one, returned **once** in `generated_password`.

**201**

Example file (Excel with Indonesian regional settings, `;` separated):

```text
Username;Nama;Kelas;No WA;Password
rina;Rina Wulandari;XI AK 1;081200000002;rahasia123
dodi;Dodi Saputra;XI AK 2;081200000003;
```

```json
{
  "data": {
    "created": 2,
    "users": [
      { "id": 4, "username": "rina", "full_name": "Rina Wulandari", "class": "XI AK 1", "phone": "081200000002", "role": "teller", "is_active": true, "...": "...", "generated_password": null },
      { "id": 5, "username": "dodi", "full_name": "Dodi Saputra", "class": "XI AK 2", "phone": "081200000003", "role": "teller", "is_active": true, "...": "...", "generated_password": "7cCQgOcUs6" }
    ]
  }
}
```

**422**: errors are keyed `rows.{index}.{field}` (index 0 = spreadsheet row 2) and the
message names the spreadsheet row:

```json
{ "message": "The username (baris 2) has already been taken.", "errors": { "rows.0.username": ["The username (baris 2) has already been taken."] } }
```

---

### Settings

Global configuration set by the administrator (soal 1a).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `min_balance` | integer 0–1,000,000 | `10000` | Balance that must remain after a withdrawal |
| `qr_scanner_mode` | `webcam`, `usb`, `disabled` | `webcam` | How the teller screen reads customer QR cards (camera, USB scanner that types like a keyboard, or manual entry only) |
| `withdrawal_verification` | `mobile`, `terminal`, `both` | `mobile` | How the customer authorizes a withdrawal: approval with PIN in the mobile app ([withdrawal authorizations](#withdrawal-authorizations-mobile-approval)), PIN typed at the teller terminal (`POST /teller/withdrawals`), or either |

`GET /settings` (any staff) and `PUT|PATCH /admin/settings` (admin, any subset):

```json
{ "data": { "min_balance": 10000, "qr_scanner_mode": "webcam", "withdrawal_verification": "mobile" } }
```

---

### Customer accounts

#### `GET /customers` · `GET /customers/{id}`

**Admin, Supervisor.** [Customer](#customer) list sorted by name (default `per_page` 15).
Closed accounts are hidden from the list by default but can always be opened by id.

| Query | Description |
| --- | --- |
| `search` | Name, NIS or account number |
| `major_id`, `class`, `group`, `status` | Filters |
| `activation` | `pending` or `activated` |
| `trashed` | `with` includes closed accounts, `only` shows only them |

#### `POST /admin/customers`

**Admin.** Open an account. The account number is generated; the account starts `active`
with balance `0` (money only enters through a teller), `activation_status: pending` and no
PIN (`pin_set: false`). `account_number`, `account_status` and `balance` in the body are
ignored. No email is sent: the student installs the app, signs in with the registered
email, enters the NIS and creates a PIN.

| Field | Rules |
| --- | --- |
| `nis` | required, max 20, unique (including closed accounts) |
| `full_name` | required, max 255 |
| `email` | required, valid email, max 255, unique (case-insensitive; stored lowercase) |
| `class` | required, `X`/`XI`/`XII` |
| `major_id` | required, existing major |
| `group` | required, `1`–`4` |
| `pin` | **prohibited**: `422 errors.pin = "PIN dibuat oleh nasabah di aplikasi Bank Mini setelah aktivasi."` |

**201**

```json
{
  "data": {
    "id": 4, "account_number": "AK-2026-001", "nis": "20260004", "full_name": "Dewi Anggraini",
    "email": "dewi.anggraini@example.test", "activation_status": "pending", "activated_at": null,
    "pin_set": false, "class": "X", "group": "2",
    "major": { "id": 4, "code": "AK", "major_name": "Akuntansi dan Keuangan Lembaga" },
    "account_status": "active", "balance": 0,
    "created_at": "2026-10-27T13:10:36.000000Z", "updated_at": "2026-10-27T13:10:36.000000Z", "deleted_at": null
  }
}
```

(Captured before the email fields existed; the `email`, `activation_status`,
`activated_at` and `pin_set` values are illustrative.)

#### `PUT|PATCH /admin/customers/{id}`

**Admin.** Any subset of `nis`, `full_name`, `email`, `class`, `major_id`, `group` and
`account_status` (`active` or `suspended` only). Account number and balance cannot be
changed. `email` can only change while the account is pending; after activation:
`422 errors.email = "Email tidak dapat diubah setelah rekening diaktifkan."`. `pin` is
refused: `422 errors.pin = "PIN dibuat oleh nasabah di aplikasi Bank Mini. Gunakan reset PIN jika nasabah lupa PIN."`

#### `POST /admin/customers/{id}/deactivate`

**Admin.** Graduation: status `inactive`, tokens revoked, the Firebase user disabled (best effort; logged if Firebase is unreachable), soft deleted.
History stays.

#### `POST /admin/customers/{id}/pin-reset`

**Admin.** Forgotten PIN: removes the PIN and lifts a PIN lock; the customer creates a new
one in the app (`POST /customer/pin/setup`). **200**
`{ "message": "PIN direset. Nasabah membuat PIN baru di aplikasi Bank Mini.", "data": Customer }`
(`pin_set: false`). Also cancels the customer's open self-service reset request.

#### Forgotten PIN: self-service requests

**Forgotten PIN, self-service (v0.2.1).** Two safeguards: an administrator must approve, and the
new PIN can only be set 24 hours after the request (`BANK_PIN_RESET_WAIT_HOURS`; a request
unused after `BANK_PIN_RESET_VALID_DAYS`, default 7, expires). Statuses: `pending` →
`approved` → `completed`; also `rejected`, `cancelled`, `expired`. One open request per customer.
While one is open, **withdrawals on the account are paused** (`422 pin_reset_in_progress`:
counter, teller authorization and in-app approval), because the old PIN may be known to someone
else; deposits are unaffected. The customer's phones get a push at each step, so the real owner
can cancel.

| Endpoint | Who | Body | Success |
| --- | --- | --- | --- |
| `GET /customer/pin-reset-request` | customer | — | `200 {data: PinResetRequest \| null}` (the open one) |
| `POST /customer/pin-reset-requests` | customer | `{id_token}` (fresh Firebase sign-in) | `201 {data: PinResetRequest}` `pending` |
| `POST /customer/pin-reset-requests/{id}/cancel` | customer | — | `200`, `cancelled` (session only) |
| `POST /customer/pin-reset-requests/{id}/complete` | customer | `{id_token, pin, pin_confirmation}` | `200 {message: "PIN berhasil diganti."}` |
| `GET /pin-reset-requests?status=&customer_id=`, `GET /pin-reset-requests/{id}` | admin, supervisor | — | `200` list / one (with `customer`, `decided_by`) |
| `POST /admin/pin-reset-requests/{id}/approve`, `/reject` | admin | `{note?}` (max 255) | `200 {data: PinResetRequest}` |

`PinResetRequest`: `{id (ULID), status, status_label, requested_at, available_at, ready,
expires_at, approved_at, rejected_at, cancelled_at, completed_at, decision_note}`; `ready` is
true once approved and `available_at` has passed. Refusals carry `code`:
`pin_not_set` 422 (nothing to reset; use `pin/setup`), `pin_reset_already_requested` 409,
`pin_reset_not_approved` 409, `pin_reset_not_ready` 409 (with `available_at`),
`pin_reset_not_usable` 409 (with `status`), `identity_mismatch` 403, `firebase_token_invalid` 401.

#### `GET /admin/customers/{id}/card`

**Admin.** Data to print the customer card. Encode `qr_payload` as the QR code.

```json
{
  "data": {
    "account_number": "AK-2026-001", "nis": "20260004", "full_name": "Dewi Anggraini",
    "class": "X", "group": "2",
    "major": { "code": "AK", "major_name": "Akuntansi dan Keuangan Lembaga" },
    "account_status": "active",
    "qr_payload": "BANKMINI:AK-2026-001"
  }
}
```

---

### Teller: shift and cash closing

A teller works inside a **shift** (one per day), which is also the daily report:

1. **Open** by counting the drawer (saldo awal).
2. Deposits, withdrawals and corrections are recorded in the open shift. Without an
   open shift dated today, they are refused.
3. **Close** by counting the cash again (uang fisik). It must equal
   `opening_balance + total_deposit − total_withdrawal` (the **system balance**).
   On a mismatch nothing is saved and the report cannot be submitted or printed.
4. A supervisor approves (the cash moves to the vault), holds or rejects it. A
   rejection reopens the shift.

Totals are net of corrections. A teller must close an old shift before opening a new one.

Cash counts are sent as `{"denominations": {"<denomination>": <quantity>, ...}}`
with the denominations listed under [Enums](#enums); quantities 0–100,000.

#### `GET /teller/shift`

The active shift (open or rejected) with **live** totals and its transactions, which
doubles as the on-screen closing draft. `can_transact` is `false` for a shift from an
earlier day (it can only be closed).

```json
{
  "data": {
    "id": 2, "report_date": "2026-10-27", "status": "open",
    "opening_balance": 150000, "opened_at": "2026-10-27T13:10:36.000000Z",
    "transaction_count": 5, "total_deposit": 132000, "total_withdrawal": 30000,
    "system_balance": 252000, "physical_balance": null, "difference": null,
    "submitted_at": null, "reviewed_at": null, "supervisor_note": null, "supervisor": null,
    "opening_counts": [
      { "denomination": 50000, "quantity": 2, "subtotal": 100000 },
      { "denomination": 10000, "quantity": 5, "subtotal": 50000 }
    ],
    "closing_counts": []
  },
  "can_transact": true,
  "transactions": [ { "id": 4, "type": "deposit", "amount": 125000, "...": "Transaction" } ]
}
```

Without an active shift: `{ "data": null, "can_transact": false, "closed_today": false }`
(`closed_today` is `true` when today's shift has already been closed).

#### `POST /teller/shift/open`

```json
{ "denominations": { "50000": 2, "10000": 5 } }
```

**201** the new [DailyReport](#dailyreport) with `status: "open"`, `opening_balance: 150000`
and `opening_counts`. **422** when a shift is still running
(`Shift tanggal 2026-10-26 masih berjalan. Tutup shift tersebut terlebih dahulu.`) or
today's shift was already closed (`Shift hari ini sudah ditutup dan tidak dapat dibuka lagi.`).

#### `POST /teller/shift/close`

```json
{ "denominations": { "100000": 2, "50000": 1, "2000": 1 } }
```

**200** the [DailyReport](#dailyreport) with `status: "submitted"`, the snapshot totals,
`physical_balance`, `difference: 0` and `closing_counts`.

**422 (cash mismatch)**: nothing is saved, the shift stays open:

```json
{
  "message": "Uang fisik kurang Rp2.000 dari saldo sistem. Periksa ulang hitungan dan slip; laporan tidak dapat ditutup atau dicetak sampai sesuai.",
  "system_balance": 252000,
  "physical_balance": 250000,
  "difference": -2000
}
```

#### `GET /teller/daily-reports` · `GET /teller/daily-reports/{id}`

The teller's own shifts, newest first (default `per_page` 15). The detail adds the
cash counts, `cash_transfer` and a top-level `transactions` list. Another teller's
report returns **404**.

#### `GET /teller/daily-reports/{id}/export`

The printable closing report (PDF, `laporan-harian-2026-10-27-teller-loket-1.pdf`)
with the recap, denomination tables, transactions and signature lines. **Locked**
until the shift is closed with matching cash:

```json
{ "message": "Laporan harian baru dapat dicetak setelah shift ditutup dengan uang fisik yang sesuai saldo sistem." }
```

---

### Teller: transactions

#### `GET /teller/customers/lookup?identifier=`

Find the customer at the counter. `identifier` is a **scanned QR payload**
(`BANKMINI:RPL-2026-001`) or a typed account number or NIS (manual fallback).
Closed accounts are not found (**404** `Nasabah tidak ditemukan.`).

```json
{
  "data": {
    "id": 1, "account_number": "RPL-2026-001", "nis": "20260001", "full_name": "Budi Santoso",
    "class": "X", "group": "1", "major_name": "Rekayasa Perangkat Lunak",
    "account_status": "active", "balance": 500000,
    "pin_locked_until": null,
    "activation_status": "activated",
    "pin_set": true
  }
}
```

`pin_locked_until` is set while the PIN is locked after wrong PINs (no withdrawal
request can be created for the customer until then).

`activation_status` is `activated` once the customer has activated the customer app
(Firebase account linked, see [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)), otherwise `pending`.
`pin_set` says whether the customer has created a PIN (in the app, after activation, and
again after an admin reset). A withdrawal of any kind needs both: for a `pending` customer
`POST /teller/withdrawal-authorizations` answers `422 customer_app_not_activated`, and
without a PIN every withdrawal answers `422 pin_not_set`.

#### Idempotency

`POST /teller/deposits`, `/teller/withdrawals` and `/teller/transactions/{id}/correct`
accept an optional header `Idempotency-Key` (letters, digits, `_ . : -`, max 100).
Send a new key per transaction and **the same key when retrying** after a timeout:

- New key: the transaction is recorded, **201**.
- Same key and same request: nothing new is recorded. **200** with header
  `Idempotent-Replayed: true` and the original transaction.
- Same key with a different amount/customer/type: **422**
  `Idempotency-Key ini sudah dipakai untuk transaksi lain. ...`

Keys are per teller.

#### `POST /teller/deposits`

Cash deposit. In one database transaction: lock the customer, add to the balance,
record the transaction, write the journal (Debit 101 Kas / Credit 201 Tabungan).

| Field | Rules |
| --- | --- |
| `account_number` | required, an existing account that is not closed |
| `amount` | required, integer 1 – 100,000,000 |

**201** a [Transaction](#transaction)

```json
{
  "data": {
    "id": 4, "type": "deposit", "type_label": "Setor tunai",
    "amount": 125000, "amount_in_words": "seratus dua puluh lima ribu rupiah",
    "balance_before": 500000, "balance_after": 625000,
    "created_at": "2026-10-27T13:10:36.000000Z", "daily_report_id": 2,
    "corrects_transaction_id": null, "note": null,
    "customer": { "id": 1, "account_number": "RPL-2026-001", "full_name": "Budi Santoso" }
  }
}
```

**422** business rules:

| Message | Cause |
| --- | --- |
| `Belum ada shift yang dibuka. Buka shift dengan menghitung saldo awal terlebih dahulu.` | No open shift |
| `Shift hari ini sudah ditutup. Transaksi baru tidak dapat dilakukan sampai laporan ditolak supervisor.` | Today's shift is closed |
| `Shift tanggal 2026-10-26 belum ditutup. Tutup shift tersebut sebelum bertransaksi.` | Yesterday's shift still open |
| `Rekening berstatus ditangguhkan dan tidak dapat bertransaksi.` | Account suspended |

#### `POST /teller/withdrawals`

Cash withdrawal, **authorized by the customer's PIN typed at the teller terminal**
(Mode B). Journal: Debit 201 Tabungan / Credit 101 Kas.

Only available when the `withdrawal_verification` setting is `terminal` or `both`.
With the default `mobile`, use [withdrawal authorizations](#withdrawal-authorizations-mobile-approval)
instead; this endpoint then answers **422**
`Penarikan harus disetujui nasabah melalui aplikasi. Buat permintaan persetujuan penarikan untuk nasabah.`

| Field | Rules |
| --- | --- |
| `account_number`, `amount` | as for deposits |
| `pin` | required, exactly 6 digits |

**201** a [Transaction](#transaction) with `type: "withdrawal"`.

**422** in addition to the deposit errors:

```json
{ "message": "Saldo tidak mencukupi. Saldo minimum Rp10.000 harus tetap tersisa; penarikan maksimal Rp990.000." }
```

```json
{ "message": "PIN salah. Sisa percobaan: 2.", "remaining_attempts": 2, "pin_locked_until": null }
```

After **3 wrong PINs in a row** the PIN is locked for **15 minutes**
(`BANK_PIN_MAX_ATTEMPTS`, `BANK_PIN_LOCK_MINUTES`); even the right PIN is refused
until then. The counter is shared with the app unlock, mobile approval and PIN change.
A correct PIN resets the counter; an admin PIN reset removes the PIN and the lock.

A customer without a PIN (not activated yet, or after an admin reset) gets
`422 {"message": "Nasabah belum membuat PIN. ...", "code": "pin_not_set"}`.

```json
{
  "message": "PIN diblokir sementara karena terlalu banyak percobaan salah. Coba lagi pukul 20:25.",
  "remaining_attempts": 0,
  "pin_locked_until": "2026-10-27T13:25:37.830610Z"
}
```

#### `GET /teller/transactions?date=`

The teller's own transactions for one day (default today), newest first, default
`per_page` 50. Each includes `corrected_by` (the correction that reversed it, or null)
and `authorized_by` (for corrections).

#### `GET /teller/transactions/{id}/receipt`

Receipt (struk) as PDF on 80 mm paper (`struk-4.pdf`): transaction number, date,
account, name, class, amount, terbilang, balance after, teller and signature lines.
Own transactions only (**404** otherwise).

---

### Teller: corrections

#### `POST /teller/transactions/{id}/correct`

Saved transactions are never edited. A mistake is reversed by a **correction**: a new
transaction of the same amount in the opposite direction (`deposit_correction` /
`withdrawal_correction`) with its own opposite journal lines, linked to the original.

Rules:

- Only the teller's **own** transaction (others return **404**), in the shift that is
  **still running** (open, or reopened by a rejection).
- Each transaction can be corrected **once**; corrections cannot be corrected.
- Needs a **supervisor's username and password** (the correction passcode) and a reason.
- Allowed on suspended accounts; a deposit correction may go below the minimum
  balance but never below zero.
- Rate limited to 10 per minute per teller. Supports `Idempotency-Key`.

| Field | Rules |
| --- | --- |
| `reason` | required, max 255 |
| `supervisor_username`, `supervisor_password` | required, an active supervisor |

```json
{ "reason": "Salah ketik, seharusnya Rp7.000", "supervisor_username": "supervisor", "supervisor_password": "password" }
```

**201**

```json
{
  "data": {
    "id": 7, "type": "deposit_correction", "type_label": "Koreksi setoran",
    "amount": 70000, "amount_in_words": "tujuh puluh ribu rupiah",
    "balance_before": 1070000, "balance_after": 1000000,
    "created_at": "2026-10-27T13:10:38.000000Z", "daily_report_id": 2,
    "corrects_transaction_id": 6, "note": "Salah ketik, seharusnya Rp7.000",
    "authorized_by": { "id": 2, "full_name": "Supervisor Bank Mini" },
    "customer": { "id": 3, "account_number": "RPL-2026-002", "full_name": "Citra Lestari" }
  }
}
```

**422**: `errors.supervisor_password` = `Otorisasi supervisor gagal. ...`;
`Transaksi ini sudah pernah dikoreksi.`; `Transaksi koreksi tidak dapat dikoreksi lagi.`;
`Koreksi hanya dapat dilakukan pada shift tempat transaksi asli dicatat, ...`;
`Saldo nasabah sudah tidak mencukupi untuk membatalkan setoran ini.`

---

### Withdrawal authorizations (mobile approval)

Mode A of [PIN verification](PIN_VERIFICATION.md): the teller starts a withdrawal,
the customer approves it **in the mobile app with their PIN**, the teller completes
it. The teller never handles the PIN, and the customer can never start a withdrawal.
Requires the `withdrawal_verification` setting `mobile` (default) or `both`.

```text
teller POST  /teller/withdrawal-authorizations          → pending   (120 s to approve)
customer POST /customer/withdrawal-authorizations/{id}/approve {pin} → approved (120 s to complete)
teller POST  /teller/withdrawal-authorizations/{id}/complete         → consumed + Transaction
```

Other outcomes: `rejected` (customer), `cancelled` (teller, or the shift was closed:
`cancel_reason` `teller` / `shift_closed`), `expired` (time ran out). Only `pending`
and `approved` are "open". `{id}` is the request's ULID (`data.id`). Another teller's
or customer's request answers **404**. Every response has `server_time`, so clients
can run an accurate countdown to `expires_at` / `approval_expires_at`.

Polling, not push: the teller polls `GET .../{id}` (every 2 s is fine) and the app
polls its list while open. Rate limits: create 10/min per teller; list/show/cancel/
complete 120/min per actor; approve/reject 5/min per customer (`429`).

**422** when a step is not possible in the current status; `status` is included when
the message is about a specific request:

```json
{ "message": "Permintaan penarikan ini berstatus \"selesai\" dan tidak dapat diproses.", "status": "consumed" }
```

#### `POST /teller/withdrawal-authorizations`

| Field | Rules |
| --- | --- |
| `account_number` | required, an existing account that is not closed |
| `amount` | required, integer 1 – 100,000,000 |

Checks everything the withdrawal will need **before** the customer is asked: open shift
for today, account `active`, customer app activated, PIN created and not locked,
`balance − amount ≥ min_balance`, no other open request of this teller, no open request for
this customer. No money moves.

**201**

```json
{
  "data": {
    "id": "01M6AMM4CNBR52V8MKC0JEDPJM", "status": "pending",
    "status_label": "Menunggu persetujuan nasabah",
    "amount": 50000, "amount_in_words": "lima puluh ribu rupiah",
    "customer": { "id": 1, "account_number": "RPL-2026-001", "full_name": "Budi Santoso", "class": "X" },
    "daily_report_id": 2,
    "created_at": "2026-11-01T04:07:01.000000Z", "expires_at": "2026-11-01T04:09:01.000000Z",
    "approved_at": null, "approval_expires_at": null, "rejected_at": null,
    "cancelled_at": null, "cancel_reason": null, "consumed_at": null, "transaction_id": null,
    "server_time": "2026-11-01T04:07:01.782795Z"
  }
}
```

**422** in addition to the deposit errors (shift, suspended account), the minimum
balance error and the PIN lock error of `POST /teller/withdrawals`:

| Message | Cause |
| --- | --- |
| `Masih ada permintaan penarikan yang belum selesai. Selesaikan atau batalkan terlebih dahulu.` | This teller already has an open request |
| `Nasabah ini masih memiliki permintaan penarikan yang belum selesai.` | The customer already has an open request (from any teller) |
| `Persetujuan penarikan melalui aplikasi nasabah sedang tidak diaktifkan.` | Setting is `terminal` |

**422 with a `code`** (nobody could approve the request, so none is created):

```json
{
  "message": "Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga belum dapat melakukan penarikan. Minta nasabah mengaktifkan akun di aplikasi dan membuat PIN terlebih dahulu.",
  "code": "customer_app_not_activated"
}
```

for `activation_status: pending` (in every mode; such a customer has no PIN, so the
terminal cannot be used either), and `422 pin_not_set` for an activated customer without a
PIN. An inactive account is reported first, with the usual account-status error.

A created request is pushed to the customer's phones (FCM, see
[push tokens](#post-customerpush-tokens--delete-customerpush-tokens)) after the response;
the app also polls.

#### `GET /teller/withdrawal-authorizations` · `GET /teller/withdrawal-authorizations/{id}`

The list returns the teller's own requests created today, newest first (paginated,
default `per_page` 15). `?status=open` returns only the open one, to resume the
waiting screen after a page reload. `GET .../{id}` is the polling endpoint; it returns
the same object as above with the current status (expiry is applied on read).

#### `POST /teller/withdrawal-authorizations/{id}/cancel`

`pending` or `approved` → `cancelled` (`cancel_reason: "teller"`). **200** with the
request. **422** if it is already finished.

#### `POST /teller/withdrawal-authorizations/{id}/complete`

Executes the approved withdrawal for the account and amount **stored in the request**
(nothing is taken from the body). In one database transaction: lock teller and shift,
lock the request (must be `approved`, not past `approval_expires_at`, same shift), lock
the customer, check status and minimum balance again, record the withdrawal, mark the
request `consumed`, write the journal. Any failure rolls everything back; the request
stays `approved` and can be retried until it expires.

**201** a [Transaction](#transaction) the first time; **200** with the same transaction
if called again (safe to retry after a timeout, no `Idempotency-Key` needed):

```json
{
  "data": {
    "id": 4, "type": "withdrawal", "type_label": "Tarik tunai",
    "amount": 50000, "amount_in_words": "lima puluh ribu rupiah",
    "balance_before": 500000, "balance_after": 450000,
    "created_at": "2026-11-01T04:07:02.000000Z", "daily_report_id": 2,
    "corrects_transaction_id": null, "note": null,
    "withdrawal_authorization": { "id": "01M6AMM4CNBR52V8MKC0JEDPJM", "method": "mobile", "approved_at": "2026-11-01T04:07:02.000000Z" },
    "customer": { "id": 1, "account_number": "RPL-2026-001", "full_name": "Budi Santoso" }
  }
}
```

**422**: not approved yet (`status: "pending"`), finished, approval expired
(`Persetujuan nasabah sudah kedaluwarsa. Buat permintaan penarikan baru.`), shift closed
or a different shift (`Permintaan penarikan ini dibuat pada shift lain.`), or the
balance no longer allows it.

#### `GET /customer/withdrawal-authorizations` · `GET /customer/withdrawal-authorizations/{id}`

The customer's own **open** requests (not paginated), or one own request in any status.
`balance_after` previews the balance after the withdrawal (`null` once the request is no
longer open).

```json
{
  "data": [
    {
      "id": "01M6AMM4CNBR52V8MKC0JEDPJM", "status": "pending",
      "status_label": "Menunggu persetujuan nasabah",
      "amount": 50000, "amount_in_words": "lima puluh ribu rupiah",
      "account_number": "RPL-2026-001", "balance": 500000, "balance_after": 450000,
      "teller": { "full_name": "Teller Loket 1" },
      "created_at": "2026-11-01T04:07:01.000000Z", "expires_at": "2026-11-01T04:09:01.000000Z",
      "approved_at": null, "approval_expires_at": null, "consumed_at": null,
      "server_time": "2026-11-01T04:07:01.909918Z"
    }
  ]
}
```

#### `POST /customer/withdrawal-authorizations/{id}/approve`

| Field | Rules |
| --- | --- |
| `pin` | required, exactly 6 digits |

Checks the PIN with the shared counter, then `pending` → `approved`
(`approval_expires_at` = now + 120 s). **200** with the request (`status: "approved"`).
The PIN is never stored or echoed.

**422**: wrong PIN (the request stays `pending` and can be approved with the right PIN):

```json
{ "message": "PIN salah. Sisa percobaan: 2.", "remaining_attempts": 2, "pin_locked_until": null }
```

PIN locked (`remaining_attempts: 0`, `pin_locked_until` set), no PIN (`pin_not_set`),
request no longer pending (`status` included), or account suspended. Staff tokens get **403**.

#### `POST /customer/withdrawal-authorizations/{id}/reject`

`pending` → `rejected`. **200** with the request. **422** if it is no longer pending.

---

### Supervisor: daily reports

Reports are reviewed one at a time (no bulk approval).

#### `GET /supervisor/daily-reports`

[DailyReport](#dailyreport) list with `teller` and `supervisor`, newest date first
(default `per_page` 15). Open shifts show live totals.

| Query | Description |
| --- | --- |
| `status` | `open`, `submitted`, `on_hold`, `approved`, `rejected` |
| `date` | `YYYY-MM-DD` |
| `teller_id` | Filter by teller (see `GET /staff?role=teller`) |

#### `GET /supervisor/daily-reports/{id}`

One report with its cash counts, `cash_transfer` and a top-level `transactions` list,
to compare with the slips and the physical cash.

#### `GET /supervisor/daily-reports/{id}/export`

Same PDF as the teller's, same lock.

#### `POST /supervisor/daily-reports/{id}/approve`

`note` optional (max 1000). Allowed from `submitted` or `on_hold`. **Approval books
the counted cash from the teller's drawer to the main vault** (soal 1c) and is final.

```json
{
  "data": {
    "id": 2, "report_date": "2026-10-27", "status": "approved",
    "opening_balance": 150000, "transaction_count": 5, "total_deposit": 132000, "total_withdrawal": 30000,
    "system_balance": 252000, "physical_balance": 252000, "difference": 0,
    "reviewed_at": "2026-10-27T13:10:39.000000Z", "supervisor_note": "Sesuai, kas diterima di brankas",
    "teller": { "id": 3, "full_name": "Teller Loket 1", "class": "XI AK 1" },
    "supervisor": { "id": 2, "full_name": "Supervisor Bank Mini" },
    "cash_transfer": { "id": 2, "amount": 252000, "transferred_at": "2026-10-27T13:10:39.000000Z" },
    "...": "other DailyReport fields"
  }
}
```

#### `POST /supervisor/daily-reports/{id}/hold`

`note` **required**. From `submitted` to `on_hold` (pending, e.g. waiting for an
original slip). The teller can still open the next day's shift.

#### `POST /supervisor/daily-reports/{id}/reject`

`note` **required**. From `submitted` or `on_hold` to `rejected`: the shift reopens so
the teller can correct and close it again. Refused while the teller is running
another shift (hold it instead).

**422** for any decision on a report not awaiting review:
`Laporan berstatus "disetujui" tidak dapat diproses.`

---

### Supervisor: vault ledger

#### `GET /supervisor/cash-transfers`

Read-only list of cash moved from teller drawers to the main vault (created by
approvals), newest first, with `total_amount` for the filter.

| Query | Description |
| --- | --- |
| `from`, `to` | `YYYY-MM-DD` |
| `teller_id` | Filter by teller |

```json
{
  "data": [
    {
      "id": 2, "source": "teller_drawer", "destination": "main_vault",
      "amount": 252000, "transferred_at": "2026-10-27T13:10:39.000000Z",
      "daily_report": { "id": 2, "report_date": "2026-10-27", "teller": { "id": 3, "full_name": "Teller Loket 1" } },
      "approved_by": { "id": 2, "full_name": "Supervisor Bank Mini" }
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 2 },
  "total_amount": 2602000
}
```

#### `GET /supervisor/cash-transfers/{id}`

One transfer with its report and approver.

---

### Audit

**Admin, Supervisor.** Read-only; there are no endpoints that write transactions or
journal lines.

Shared filters (interview: date range first, then teller and customer):

| Query | Description |
| --- | --- |
| `from`, `to` | `YYYY-MM-DD` (whole day) or `YYYY-MM-DD HH:MM` (exact) |
| `teller` | Teller name or username (contains) |
| `customer` | Customer name, account number or NIS (contains) |
| `type` | Transaction type |
| `teller_id`, `customer_id` | Exact ids |

#### `GET /audit/transactions` · `GET /audit/transactions/{id}`

The transaction log (real-time log), newest first (default `per_page` 15), with
`customer`, `teller`, `authorized_by` and `corrected_by`. The detail adds `journals`
and, for a withdrawal approved in the mobile app, `withdrawal_authorization`
(`{ "id": "01M6...", "method": "mobile", "approved_at": "..." }`; absent for PIN-at-terminal
withdrawals).

```json
{
  "data": {
    "id": 6, "type": "deposit", "type_label": "Setor tunai", "amount": 70000,
    "balance_before": 1000000, "balance_after": 1070000, "daily_report_id": 2,
    "corrected_by": { "id": 7, "type": "deposit_correction", "created_at": "2026-10-27T13:10:38.000000Z" },
    "customer": { "id": 3, "account_number": "RPL-2026-002", "full_name": "Citra Lestari" },
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "journals": [
      { "id": 12, "transaction_id": 6, "account_code": "201", "account_name": "Tabungan Nasabah", "position": "credit", "amount": 70000, "created_at": "..." },
      { "id": 11, "transaction_id": 6, "account_code": "101", "account_name": "Kas", "position": "debit", "amount": 70000, "created_at": "..." }
    ],
    "...": "other Transaction fields"
  }
}
```

#### `GET /audit/journals`

Journal lines, newest first (default `per_page` 30), with the transaction's teller
and customer. Extra filters: `transaction_id`, `account_code`, `position`.

```json
{
  "data": [
    {
      "id": 16, "transaction_id": 8, "account_code": "201", "account_name": "Tabungan Nasabah",
      "position": "credit", "amount": 7000, "created_at": "2026-10-27T13:10:38.000000Z",
      "transaction": {
        "id": 8, "type": "deposit", "customer_id": 3, "user_id": 3,
        "customer": { "account_number": "RPL-2026-002", "full_name": "Citra Lestari" },
        "teller": { "full_name": "Teller Loket 1" }
      }
    }
  ]
}
```

#### `GET /audit/journals/summary`

Trial balance per account with the same filters. Double entry guarantees `is_balanced`.

```json
{
  "data": {
    "accounts": [
      { "account_code": "101", "account_name": "Kas", "total_debit": 2452000, "total_credit": 100000 },
      { "account_code": "201", "account_name": "Tabungan Nasabah", "total_debit": 100000, "total_credit": 2452000 }
    ],
    "total_debit": 2552000, "total_credit": 2552000, "is_balanced": true
  }
}
```

---

### Reports and PDF exports

All exports are PDF (readable and printable, as requested in the interview; not CSV).
List exports print at most 5,000 rows and say so when truncated.

| Endpoint | Who | File | Content |
| --- | --- | --- | --- |
| `GET /audit/journals/export` | Admin, Supervisor | `jurnal-akuntansi.pdf` | Laporan keuangan: totals per account, balance check, every journal line with teller, customer and account number. Same filters as `/audit/journals` |
| `GET /audit/transactions/export` | Admin, Supervisor | `jejak-audit-transaksi.pdf` | Audit trail with balances before/after and correction references. Same filters as `/audit/transactions` |
| `GET /customers/{id}/statement?from=&to=` | Admin, Supervisor | `mutasi-RPL-2026-001.pdf` | Kartu simpanan: opening balance, every line with running balance, totals |
| `GET /customer/statement/export?from=&to=` | Nasabah | `mutasi-RPL-2026-001.pdf` | The customer's own statement |
| `GET /teller/transactions/{id}/receipt` | Teller (own) | `struk-4.pdf` | Receipt on 80 mm paper |
| `GET /teller/daily-reports/{id}/export` | Teller (own) | `laporan-harian-....pdf` | Closing report; locked until balanced |
| `GET /supervisor/daily-reports/{id}/export` | Supervisor | `laporan-harian-....pdf` | Same |
| `GET /reports/annual?school_year=2026` | Admin, Supervisor | `laporan-tahunan-2026-2027.pdf` | One school year (July–June, default current): balance per product and per account (opening, deposits, withdrawals, closing; net of corrections) |

---

### Customer portal

**Nasabah.** Everything is scoped to the token's account; `?customer_id=` and similar
parameters are ignored.

| Endpoint | Description |
| --- | --- |
| `GET /customer/me` | Own [Customer](#customer) |
| `GET /customer/balance` | `{ "account_number": "RPL-2026-001", "full_name": "Budi Santoso", "account_status": "active", "balance": 595000, "min_balance": 10000, "as_of": "2026-10-27T13:10:40.302817Z" }` |
| `GET /customer/card` | Digital card for the app, same shape as the admin card, with `qr_payload` to show as a QR code at the counter |
| `GET /customer/transactions` | Statement, newest first, default `per_page` 20. Filters `from`, `to`, `type` |
| `GET /customer/transactions/{id}` | One own line (**404** for others) |
| `GET /customer/statement/export` | Own statement as PDF |
| `POST /customer/pin/setup` | Create the PIN, see [above](#post-customerpinsetup) |
| `POST /customer/pin` | Change PIN |
| `POST`, `DELETE /customer/push-tokens` | Register / remove the phone's FCM token, see [above](#post-customerpush-tokens--delete-customerpush-tokens) |
| `POST /customer/unlock` | `{pin}` → `200 {"message":"PIN benar."}`: unlock the app during a session. Wrong PIN `422` with `remaining_attempts`; `422 pin_not_set` before a PIN exists; shared lock; 5 per minute |
| `POST /customer/logout` | Revoke the current token |
| `POST /customer/logout-all` | Revoke all tokens of the customer |
| `GET /customer/withdrawal-authorizations` (and `/{id}`, `/{id}/approve`, `/{id}/reject`) | Approve or reject a withdrawal the teller started, see [withdrawal authorizations](#withdrawal-authorizations-mobile-approval) |

Statement lines ([StatementLine](#statementline)) leave out tellers and journals:

```json
{
  "data": [
    { "id": 5, "type": "withdrawal", "direction": "out", "description": "Tarik tunai", "amount": 30000, "balance_after": 595000, "created_at": "2026-10-27T13:10:37.000000Z" },
    { "id": 4, "type": "deposit", "direction": "in", "description": "Setor tunai", "amount": 125000, "balance_after": 625000, "created_at": "2026-10-27T13:10:36.000000Z" }
  ]
}
```

#### `POST /customer/pin`

| Field | Rules |
| --- | --- |
| `current_pin` | required, 6 digits |
| `new_pin` | required, 6 digits, different from `current_pin`, confirmed |
| `new_pin_confirmation` | must equal `new_pin` |

**200** `{ "message": "PIN berhasil diubah." }`. A wrong `current_pin` counts toward the
same lock as the app unlock and withdrawals (**422** with `remaining_attempts`). Without a
PIN yet: `422 pin_not_set` (use `POST /customer/pin/setup`).

---

## Objects

Passwords, PINs and idempotency keys never appear in responses.

### Staff

`id`, `username`, `full_name`, `class` (nullable), `phone` (nullable), `role`,
`is_active`, `created_at`, `updated_at`.

### Customer

| Field | Notes |
| --- | --- |
| `id`, `nis`, `full_name`, `class`, `group` | |
| `email` | Registered email (nullable for accounts created before emails were required) |
| `activation_status` | `pending` until the customer activates the app, then `activated` |
| `activated_at` | When the app was activated, or null |
| `pin_set` | Whether the customer has created a PIN (only possible after activation) |
| `account_number` | e.g. `RPL-2026-001`, generated |
| `major` | `{ id, code, major_name }` |
| `account_status` | `active`, `suspended`, `inactive` |
| `balance` | Rupiah |
| `created_at`, `updated_at`, `deleted_at` | `deleted_at` is set when the account is closed |

### Transaction

| Field | Notes |
| --- | --- |
| `id`, `type`, `type_label` | Label in Indonesian (`Setor tunai`, `Koreksi setoran`, ...) |
| `amount`, `amount_in_words` | Always > 0; terbilang for slips and receipts |
| `balance_before`, `balance_after` | Customer balance around the transaction |
| `created_at`, `daily_report_id` | The shift it belongs to |
| `corrects_transaction_id`, `note` | Corrections: the reversed transaction and the reason |
| `authorized_by` | Corrections: `{ id, full_name }` of the supervisor |
| `corrected_by` | Ordinary transactions: `{ id, type, created_at }` of the correction, or null |
| `customer` | `{ id, account_number, full_name }` |
| `teller` | `{ id, full_name }`, audit endpoints |
| `journals` | `GET /audit/transactions/{id}` only |
| `withdrawal_authorization` | Withdrawals approved in the mobile app: `{ id, method: "mobile", approved_at }`. Returned by `complete` and the audit detail |

### Journal

`id`, `transaction_id`, `account_code`, `account_name`, `position`, `amount` (equals
the transaction amount; exactly one debit and one credit per transaction),
`created_at`, and on `/audit/journals` a `transaction` summary with customer and teller.

### DailyReport

| Field | Notes |
| --- | --- |
| `id`, `report_date`, `status` | One per teller per day |
| `opening_balance`, `opened_at` | Saldo awal |
| `transaction_count`, `total_deposit`, `total_withdrawal` | Net of corrections; live while open |
| `system_balance` | `opening_balance + total_deposit − total_withdrawal` |
| `physical_balance`, `difference` | Counted cash and `physical − system` (0 once closed); null while a shift is `open`. A `rejected` shift keeps the previous count (and its `closing_counts`) until it is closed again |
| `submitted_at`, `reviewed_at`, `supervisor_note` | |
| `teller` | `{ id, full_name, class }` |
| `supervisor` | `{ id, full_name }` of the reviewer, or null |
| `opening_counts`, `closing_counts` | `[{ denomination, quantity, subtotal }]` |
| `cash_transfer` | `{ id, amount, transferred_at }` after approval, or null |

### CashTransfer

`id`, `source` (`teller_drawer`), `destination` (`main_vault`), `amount`,
`transferred_at`, `daily_report` `{ id, report_date, teller }`, `approved_by`
`{ id, full_name }`.

### StatementLine

`id`, `type`, `direction` (`in`/`out`), `description`, `amount`, `balance_after`, `created_at`.

---

## Workflows

### Teller's day

1. `POST /login`, then `GET /teller/shift`. If `data` is null, count the drawer and
   `POST /teller/shift/open`.
2. For each customer: scan the card QR (or type the account number/NIS) →
   `GET /teller/customers/lookup` → check name, status and balance.
3. Deposit: `POST /teller/deposits` with a new `Idempotency-Key`. Withdrawal (default
   `mobile` mode): `POST /teller/withdrawal-authorizations` → the customer approves in
   the app with their PIN while the teller polls `GET .../{id}` → on `approved`,
   `POST .../{id}/complete`. In `terminal` mode the customer types the PIN on the
   confirmation screen → `POST /teller/withdrawals`.
4. Print `GET /teller/transactions/{id}/receipt`.
5. Mistake? `POST /teller/transactions/{id}/correct` with the supervisor's credentials,
   then record the right amount.
6. End of shift: count the cash → `POST /teller/shift/close`. On a mismatch, recount
   and check the slips; correct if needed. Then print `GET /teller/daily-reports/{id}/export`.

### Supervisor's review

1. `GET /supervisor/daily-reports?status=submitted` (pending list).
2. `GET /supervisor/daily-reports/{id}`: compare transactions and counts with the slips and cash.
3. `approve` (cash goes to the vault, see `GET /supervisor/cash-transfers`), `hold`
   with a note, or `reject` with a note (the teller corrects and closes again).
4. Investigations: `GET /audit/transactions?from=...&to=...&teller=...&customer=...`.

### Administrator

- Staff: create, import from a spreadsheet, block/unblock, delete unused accounts.
- Accounts: open with the student's email (number generated, no PIN), print the card with
  QR, reset a forgotten PIN (`pin-reset`), suspend, graduate (`deactivate`).
- Settings: minimum balance, scanner mode and withdrawal verification mode.
- Reports: journal, audit trail and annual report as PDF.

### Customer (mobile app)

First time: sign in with Google or email/password (Firebase verifies the email) →
`POST /customer/auth/login` answers `account_not_linked` → enter the NIS →
`POST /customer/activation` → create the PIN with `POST /customer/pin/setup`.
Later: Firebase sign-in → `POST /customer/auth/login`; while the session lasts, unlock with
`POST /customer/unlock` → `GET /customer/balance`,
`GET /customer/transactions`, `GET /customer/card` (show the QR at the counter),
`POST /customer/pin`, `POST /customer/logout`. At the counter, when the teller starts a
withdrawal: poll `GET /customer/withdrawal-authorizations` → show amount, teller and
balance after → `POST .../{id}/approve` with the PIN, or `POST .../{id}/reject`.
