# E-Teller Bank Mini — API Reference

REST API for the school mini bank. One login gateway serves four roles:
Administrator, Supervisor and Teller (staff web app) and Nasabah (customer
mobile app). All examples are real responses captured from the seeded demo
database; long lists are shortened.

## Contents

- [Conventions](#conventions)
- [Authentication](#authentication)
- [Errors](#errors)
- [Pagination](#pagination)
- [Access matrix](#access-matrix)
- [Endpoint summary](#endpoint-summary)
- [Endpoints](#endpoints)
  - [Login gateway](#login-gateway)
  - [Shared staff endpoints](#shared-staff-endpoints)
  - [Staff management](#staff-management)
  - [Settings](#settings)
  - [Customer accounts](#customer-accounts)
  - [Teller: shift and cash closing](#teller-shift-and-cash-closing)
  - [Teller: transactions](#teller-transactions)
  - [Teller: corrections](#teller-corrections)
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
| Cash denominations | `100000`, `50000`, `20000`, `10000`, `5000`, `2000`, `1000`, `500`, `200`, `100` |

### Account numbers

`{major code}-{school year}-{serial}`, e.g. `RPL-2026-001`. The school year runs
July–June (an account opened in October 2026 or June 2027 gets `2026`). The serial
restarts for every major and school year and is never reused. Major codes: AK, BR,
BD, MP, DKV, RPL, TKJ, LPS.

---

## Authentication

Laravel Sanctum bearer tokens, issued by the single login gateway `POST /login`.
Staff tokens only work on staff endpoints, customer tokens only on `/customer/*`.

- Tokens expire after **8 hours** (`SANCTUM_EXPIRATION`). An expired token returns `401`.
- Logout revokes only the token used for that request.
- Login is limited to **5 attempts per minute** per username/identifier and IP (`429`).
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
| `403` | Wrong actor or role, or a blocked/closed account | `{ "message": "Peran Anda tidak memiliki akses ke fitur ini." }` |
| `404` | Record does not exist or belongs to someone else | `{ "message": "Data tidak ditemukan." }` |
| `405` | Method not allowed on that URL (e.g. writing to the journal) | `{ "message": "The DELETE method is not supported for route ..." }` |
| `409` | Staff delete refused because of history | `{ "message": "Staf ini memiliki riwayat transaksi, ... Blokir akunnya saja." }` |
| `422` | Field validation failed | `{ "message": "...", "errors": { "amount": ["The amount field must be at least 1."] } }` |
| `422` | Business rule refused the request | `{ "message": "Saldo tidak mencukupi. Saldo minimum Rp10.000 harus tetap tersisa; penarikan maksimal Rp990.000." }` |
| `429` | Too many login/correction attempts | `{ "message": "Too Many Attempts." }` |
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
| Accounts (open, edit, close, print card) | C, R, U | R (lookup) | R | — |
| QR scan lookup | Configure (settings) | R (execute) | — | — |
| Deposit / withdrawal / correction | R (audit log) | C | R (audit log) | — |
| Withdrawal PIN | — | — | — | Enters PIN at the counter |
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
| `POST` | `/staff/login`, `/customer/login` | Public (aliases of `/login`) |
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
| `POST` | `/admin/customers/{id}/deactivate` | Admin |
| `GET` | `/admin/customers/{id}/card` | Admin |
| `GET` | `/teller/shift` · `POST` `/teller/shift/open`, `/teller/shift/close` | Teller |
| `GET` | `/teller/daily-reports`, `/teller/daily-reports/{id}`, `/teller/daily-reports/{id}/export` (PDF) | Teller |
| `GET` | `/teller/customers/lookup` | Teller |
| `POST` | `/teller/deposits`, `/teller/withdrawals` | Teller |
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
| `POST` | `/customer/pin` · `/customer/logout` | Nasabah |

---

## Endpoints

### Login gateway

#### `POST /login`

The single entry point for all four roles (soal: "satu pintu gerbang login terpusat").
Send **either** staff or customer credentials. `data.actor` tells the client which
portal to open. `/staff/login` and `/customer/login` accept the same bodies.

| Field | Rules |
| --- | --- |
| `username`, `password` | Staff: required together |
| `identifier`, `pin` | Customer: NIS or account number (max 50) and a 6-digit PIN |

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

**200 (customer)**: `actor` is `"customer"` and `data.customer` is a [Customer](#customer).

**422**: wrong credentials (`Username atau password salah.` /
`NIS/nomor rekening atau PIN salah.`), blocked staff, closed account, or neither
kind of credentials:

```json
{
  "message": "Isi username dan password (staf) atau NIS/nomor rekening dan PIN (nasabah).",
  "errors": { "username": ["Isi username dan password (staf) atau NIS/nomor rekening dan PIN (nasabah)."] }
}
```

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

`GET /settings` (any staff) and `PUT|PATCH /admin/settings` (admin, any subset):

```json
{ "data": { "min_balance": 10000, "qr_scanner_mode": "webcam" } }
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
| `trashed` | `with` includes closed accounts, `only` shows only them |

#### `POST /admin/customers`

**Admin.** Open an account. The account number is generated; the account starts
`active` with balance `0` (money only enters through a teller). `account_number`,
`account_status` and `balance` in the body are ignored.

| Field | Rules |
| --- | --- |
| `nis` | required, max 20, unique (including closed accounts) |
| `full_name` | required, max 255 |
| `class` | required, `X`/`XI`/`XII` |
| `major_id` | required, existing major |
| `group` | required, `1`–`4` |
| `pin` | required, exactly 6 digits (stored hashed) |

**201**

```json
{
  "data": {
    "id": 4, "account_number": "AK-2026-001", "nis": "20260004", "full_name": "Dewi Anggraini",
    "class": "X", "group": "2",
    "major": { "id": 4, "code": "AK", "major_name": "Akuntansi dan Keuangan Lembaga" },
    "account_status": "active", "balance": 0,
    "created_at": "2026-10-27T13:10:36.000000Z", "updated_at": "2026-10-27T13:10:36.000000Z", "deleted_at": null
  }
}
```

#### `PUT|PATCH /admin/customers/{id}`

**Admin.** Any subset of `nis`, `full_name`, `class`, `major_id`, `group`,
`account_status` (`active` or `suspended` only) and `pin` (6 digits; a PIN reset
also lifts a PIN lock). Account number and balance cannot be changed.

#### `POST /admin/customers/{id}/deactivate`

**Admin.** Graduation: status `inactive`, tokens revoked, soft deleted. History stays.

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
    "pin_locked_until": null
  }
}
```

`pin_locked_until` is set while withdrawals are locked after wrong PINs.

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

Cash withdrawal, **authorized by the customer's PIN** typed on the counter's
confirmation screen (soal 1d). Journal: Debit 201 Tabungan / Credit 101 Kas.

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

After **3 wrong PINs in a row** withdrawals are locked for **15 minutes**
(`BANK_PIN_MAX_ATTEMPTS`, `BANK_PIN_LOCK_MINUTES`); even the right PIN is refused
until then. A correct PIN resets the counter; an admin PIN reset lifts the lock.

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
`customer`, `teller`, `authorized_by` and `corrected_by`. The detail adds `journals`.

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
| `POST /customer/pin` | Change PIN |
| `POST /customer/logout` | Revoke the current token |

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
same lock as withdrawals (**422** with `remaining_attempts`).

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
3. Deposit: `POST /teller/deposits` with a new `Idempotency-Key`. Withdrawal: the
   customer types their PIN on the confirmation screen → `POST /teller/withdrawals`.
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
- Accounts: open (number generated), print the card with QR, reset PIN, suspend,
  graduate (`deactivate`).
- Settings: minimum balance and scanner mode.
- Reports: journal, audit trail and annual report as PDF.

### Customer (mobile app)

`POST /login` with NIS/account number and PIN → `GET /customer/balance`,
`GET /customer/transactions`, `GET /customer/card` (show the QR at the counter),
`POST /customer/pin`, `POST /customer/logout`.
