# E-Teller Bank Mini — API Reference

REST API for the school mini bank: staff portal (Administrator, Supervisor, Teller)
and customer portal (students). All examples below are real responses captured
from the seeded demo database.

## Contents

- [Conventions](#conventions)
- [Authentication](#authentication)
- [Errors](#errors)
- [Pagination](#pagination)
- [Access matrix](#access-matrix)
- [Endpoints](#endpoints)
  - [Staff authentication](#staff-authentication)
  - [Customer authentication](#customer-authentication)
  - [Majors](#majors)
  - [Staff management (Admin)](#staff-management-admin)
  - [Customer accounts (Admin, Supervisor)](#customer-accounts-admin-supervisor)
  - [Teller: transactions](#teller-transactions)
  - [Teller: daily report](#teller-daily-report)
  - [Teller: cash drawer and transfers](#teller-cash-drawer-and-transfers)
  - [Supervisor: daily reports](#supervisor-daily-reports)
  - [Supervisor: cash transfers](#supervisor-cash-transfers)
  - [Audit (Admin, Supervisor)](#audit-admin-supervisor)
  - [Customer portal](#customer-portal)
- [Objects](#objects)
- [Workflows](#workflows)

---

## Conventions

| Item | Rule |
| --- | --- |
| Base URL | `http://localhost:8000/api` (whatever host serves the app, plus `/api`) |
| Headers | `Accept: application/json` on every request, `Content-Type: application/json` when sending a body, `Authorization: Bearer <token>` on every endpoint except login |
| Money | Integer Rupiah, no decimals (`50000` = Rp50.000) |
| Timestamps | ISO 8601 in UTC, e.g. `2026-10-23T06:17:25.000000Z` (= 13:17 WIB). Convert to local time in the client |
| Dates | `YYYY-MM-DD` in the school's timezone (Asia/Jakarta), e.g. `report_date`, `?date=`, `?from=`, `?to=` |
| Single objects | Wrapped in `data` |
| Lists | `data` array plus `links` and `meta` (see [Pagination](#pagination)) |
| Messages | Business-rule messages are Indonesian; field validation messages come from Laravel in English |

### Enums

| Field | Values |
| --- | --- |
| Staff `role` | `admin`, `supervisor`, `teller` |
| Customer `account_status` | `active` (can transact), `suspended` (can log in and view, cannot transact), `inactive` (graduated, cannot log in, soft deleted) |
| Customer `class` | `X`, `XI`, `XII` |
| Customer `group` | `1`, `2`, `3`, `4` |
| Transaction `type` | `deposit`, `withdrawal` |
| Journal `account_code` | `101` (Kas), `201` (Tabungan Nasabah) |
| Journal `position` | `debit`, `credit` |
| Daily report `status` | `submitted`, `approved`, `rejected` (`draft` exists in the schema but drafts are never stored, use the preview endpoint) |
| Cash transfer `status` | `pending`, `approved`, `rejected` |

---

## Authentication

Laravel Sanctum bearer tokens. Staff and customers log in through separate
endpoints and receive a token that only works on their own portal.

- Tokens expire after **8 hours** (`SANCTUM_EXPIRATION`, in minutes). An expired token returns `401`.
- Logout revokes only the token used for that request.
- Login is limited to **5 attempts per minute** per username/identifier and IP. Further attempts return `429`.
- Deactivating a staff member or closing a customer account revokes all their tokens immediately.

```http
POST /api/staff/login
Accept: application/json
Content-Type: application/json

{ "username": "teller", "password": "password" }
```

Use `data.token` from the response on later requests:

```http
GET /api/staff/me
Accept: application/json
Authorization: Bearer 3|PoaaOcaZraufqaBaHcGgRSexkssRnJ31N5xngHSD9bb886a4
```

---

## Errors

Every error has a `message`. Validation errors also have `errors`, keyed by field.

| Status | When | Example body |
| --- | --- | --- |
| `401` | Missing, invalid or expired token | `{ "message": "Unauthenticated." }` |
| `403` | Wrong actor or role, or a deactivated account | `{ "message": "Peran Anda tidak memiliki akses ke fitur ini." }` |
| `404` | Record does not exist, or belongs to someone else | `{ "message": "Data tidak ditemukan." }` |
| `405` | Method not supported on that URL (e.g. writing to the journal) | `{ "message": "The DELETE method is not supported for route ..." }` |
| `422` | Field validation failed | `{ "message": "...", "errors": { "amount": ["The amount field must be at least 1."] } }` |
| `422` | A business rule refused the request | `{ "message": "Saldo tidak mencukupi. Saldo minimum Rp10.000 harus tetap tersisa; penarikan maksimal Rp515.000." }` |
| `429` | Too many login attempts | `{ "message": "Too Many Attempts." }` |
| `500` | Unexpected server error (the money operation is fully rolled back) | `{ "message": "Server Error" }` |

The `403` messages tell you which check failed:

| Message | Meaning |
| --- | --- |
| `Fitur ini khusus untuk staf bank.` | A customer token was used on a staff endpoint |
| `Fitur ini khusus untuk nasabah.` | A staff token was used on a customer endpoint |
| `Peran Anda tidak memiliki akses ke fitur ini.` | Staff member with the wrong role |
| `Akun staf ini sudah dinonaktifkan.` | Staff account was deactivated |
| `Rekening ini sudah tidak aktif.` | Customer account is `inactive` |

A role check always runs before the record is looked up, so an unauthorized
caller gets `403`, never `404`, and cannot probe which IDs exist.

---

## Pagination

List endpoints accept `?page=` (default 1) and `?per_page=` (between 1 and 100;
the default is listed per endpoint).

```json
{
  "data": [ ... ],
  "links": {
    "first": "http://127.0.0.1:8123/api/customers?page=1",
    "last": "http://127.0.0.1:8123/api/customers?page=2",
    "prev": null,
    "next": "http://127.0.0.1:8123/api/customers?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "links": [ ... ],
    "path": "http://127.0.0.1:8123/api/customers",
    "per_page": 2,
    "to": 2,
    "total": 4
  }
}
```

Later examples show only `data` for lists.

---

## Access matrix

| Area | Admin | Supervisor | Teller | Customer |
| --- | :-: | :-: | :-: | :-: |
| Staff management | ✓ | | | |
| Open / edit / close accounts | ✓ | | | |
| Read account list | ✓ | ✓ | | |
| Look up one customer | | | ✓ | |
| Deposit and withdraw | | | ✓ | |
| Daily report: prepare and submit | | | ✓ | |
| Daily report: approve or reject | | ✓ | | |
| Cash transfer: request | | | ✓ | |
| Cash transfer: authorize | | ✓ | | |
| Audit transactions and journal (read-only) | ✓ | ✓ | | |
| Own balance and statement | | | | ✓ |

---

## Endpoints

### Summary

| Method | Path | Who |
| --- | --- | --- |
| `POST` | `/staff/login` | Public |
| `GET` | `/staff/me` | Staff |
| `POST` | `/staff/logout` | Staff |
| `POST` | `/customer/login` | Public |
| `GET` | `/customer/me` | Customer |
| `POST` | `/customer/logout` | Customer |
| `GET` | `/majors` | Staff |
| `GET` | `/admin/staff` | Admin |
| `POST` | `/admin/staff` | Admin |
| `GET` | `/admin/staff/{id}` | Admin |
| `PUT`/`PATCH` | `/admin/staff/{id}` | Admin |
| `DELETE` | `/admin/staff/{id}` | Admin |
| `GET` | `/customers` | Admin, Supervisor |
| `GET` | `/customers/{id}` | Admin, Supervisor |
| `POST` | `/admin/customers` | Admin |
| `PUT`/`PATCH` | `/admin/customers/{id}` | Admin |
| `POST` | `/admin/customers/{id}/deactivate` | Admin |
| `GET` | `/teller/customers/lookup` | Teller |
| `POST` | `/teller/deposits` | Teller |
| `POST` | `/teller/withdrawals` | Teller |
| `GET` | `/teller/transactions` | Teller |
| `GET` | `/teller/daily-reports/preview` | Teller |
| `GET` | `/teller/daily-reports` | Teller |
| `POST` | `/teller/daily-reports` | Teller |
| `GET` | `/teller/daily-reports/{id}` | Teller |
| `GET` | `/teller/drawer` | Teller |
| `GET` | `/teller/cash-transfers` | Teller |
| `POST` | `/teller/cash-transfers` | Teller |
| `GET` | `/supervisor/daily-reports` | Supervisor |
| `GET` | `/supervisor/daily-reports/{id}` | Supervisor |
| `POST` | `/supervisor/daily-reports/{id}/approve` | Supervisor |
| `POST` | `/supervisor/daily-reports/{id}/reject` | Supervisor |
| `GET` | `/supervisor/cash-transfers` | Supervisor |
| `GET` | `/supervisor/cash-transfers/{id}` | Supervisor |
| `POST` | `/supervisor/cash-transfers/{id}/approve` | Supervisor |
| `POST` | `/supervisor/cash-transfers/{id}/reject` | Supervisor |
| `GET` | `/audit/transactions` | Admin, Supervisor |
| `GET` | `/audit/transactions/{id}` | Admin, Supervisor |
| `GET` | `/audit/journals` | Admin, Supervisor |
| `GET` | `/audit/journals/summary` | Admin, Supervisor |
| `GET` | `/customer/balance` | Customer |
| `GET` | `/customer/transactions` | Customer |
| `GET` | `/customer/transactions/{id}` | Customer |

---

### Staff authentication

#### `POST /staff/login`

Log in an administrator, supervisor or teller.

| Field | Rules |
| --- | --- |
| `username` | required, string |
| `password` | required, string |

**200**

```json
{
  "data": {
    "token": "3|PoaaOcaZraufqaBaHcGgRSexkssRnJ31N5xngHSD9bb886a4",
    "token_type": "Bearer",
    "user": {
      "id": 3,
      "username": "teller",
      "full_name": "Teller Loket 1",
      "role": "teller",
      "is_active": true,
      "created_at": "2026-10-23T06:17:21.000000Z",
      "updated_at": "2026-10-23T06:17:21.000000Z"
    }
  }
}
```

**422** wrong credentials (`Username atau password salah.`) or a deactivated account (`Akun staf ini sudah dinonaktifkan.`), both on the `username` field:

```json
{
  "message": "Username atau password salah.",
  "errors": { "username": ["Username atau password salah."] }
}
```

#### `GET /staff/me`

The logged-in staff member as a [Staff](#staff) object.

#### `POST /staff/logout`

Revokes the current token.

```json
{ "message": "Berhasil keluar." }
```

---

### Customer authentication

#### `POST /customer/login`

Log in a student with their NIS **or** account number and 6-digit PIN.

| Field | Rules |
| --- | --- |
| `identifier` | required, string, max 50. NIS or account number |
| `pin` | required, exactly 6 digits |

**200** (the customer is a [Customer](#customer) object)

```json
{
  "data": {
    "token": "4|UIawR2Uml7G79hPKZeKidnhyJVkVHKDGnnNEmywJ6af99c26",
    "token_type": "Bearer",
    "customer": {
      "id": 1,
      "account_number": "1000000001",
      "nis": "20260001",
      "full_name": "Budi Santoso",
      "class": "X",
      "group": "1",
      "major": { "id": 1, "major_name": "Rekayasa Perangkat Lunak" },
      "account_status": "active",
      "balance": 500000,
      "created_at": "2026-10-23T06:17:21.000000Z",
      "updated_at": "2026-10-23T06:17:21.000000Z",
      "deleted_at": null
    }
  }
}
```

**422** on `identifier`: `NIS/nomor rekening atau PIN salah.` or `Rekening ini sudah tidak aktif.`; on `pin`: wrong format.

Suspended customers can still log in.

#### `GET /customer/me`

The logged-in customer as a [Customer](#customer) object.

#### `POST /customer/logout`

Revokes the current token. Returns `{ "message": "Berhasil keluar." }`.

---

### Majors

#### `GET /majors`

**Who:** any staff. Every major, sorted by name, for the account form's dropdown. Not paginated.

```json
{
  "data": [
    { "id": 4, "major_name": "Akuntansi dan Keuangan Lembaga" },
    { "id": 6, "major_name": "Bisnis Daring" },
    { "id": 1, "major_name": "Rekayasa Perangkat Lunak" }
  ]
}
```

---

### Staff management (Admin)

Administrators manage teller and supervisor accounts. Administrator accounts
can be viewed but not changed through the API.

#### `GET /admin/staff`

Paginated list of [Staff](#staff), sorted by name. Default `per_page`: 15.

| Query | Description |
| --- | --- |
| `status` | `active` (default), `inactive` or `all` |
| `role` | `admin`, `supervisor` or `teller` |
| `search` | Matches username or full name |

#### `POST /admin/staff`

Create a teller or supervisor.

| Field | Rules |
| --- | --- |
| `username` | required, letters/numbers/`-`/`_`, max 50, unique |
| `full_name` | required, max 255 |
| `password` | required, min 8 characters |
| `role` | required, `teller` or `supervisor` (creating `admin` fails validation) |

```json
{ "username": "teller2", "full_name": "Teller Loket 2", "password": "rahasia123", "role": "teller" }
```

**201**

```json
{
  "data": {
    "id": 4,
    "username": "teller2",
    "full_name": "Teller Loket 2",
    "role": "teller",
    "is_active": true,
    "created_at": "2026-10-23T06:17:25.000000Z",
    "updated_at": "2026-10-23T06:17:25.000000Z"
  }
}
```

#### `GET /admin/staff/{id}`

One [Staff](#staff) object.

#### `PUT|PATCH /admin/staff/{id}`

Update any subset of fields. Setting `is_active` to `false` also revokes their tokens; `true` reactivates the account.

| Field | Rules |
| --- | --- |
| `username` | letters/numbers/`-`/`_`, max 50, unique |
| `full_name` | max 255 |
| `password` | min 8 characters |
| `role` | `teller` or `supervisor` |
| `is_active` | boolean |

```json
{ "full_name": "Teller Loket Dua", "role": "supervisor" }
```

**200** the updated [Staff](#staff). **403** if the target is an administrator:

```json
{ "message": "Akun administrator tidak dapat diubah melalui API." }
```

#### `DELETE /admin/staff/{id}`

Removes access without deleting the record, because their transactions must keep
pointing at them: sets `is_active` to `false` and revokes their tokens.
**200** the updated [Staff](#staff) (`"is_active": false`). **403** for administrators.

---

### Customer accounts (Admin, Supervisor)

#### `GET /customers`

**Who:** Admin, Supervisor. Paginated list of [Customer](#customer), sorted by name. Default `per_page`: 15.

| Query | Description |
| --- | --- |
| `search` | Matches full name, NIS or account number |
| `major_id` | Filter by major |
| `class` | `X`, `XI`, `XII` |
| `group` | `1`–`4` |
| `status` | `active`, `suspended`, `inactive` |
| `trashed` | Closed accounts are hidden by default. `with` includes them, `only` shows only them |

#### `GET /customers/{id}`

**Who:** Admin, Supervisor. One [Customer](#customer). Closed (graduated) accounts can still be viewed.

#### `POST /admin/customers`

**Who:** Admin. Open a savings account. The system generates the next 10-digit
account number; every account starts `active` with balance `0`, because money only
comes in through a teller deposit. Any `account_number`, `account_status` or
`balance` sent is ignored.

| Field | Rules |
| --- | --- |
| `nis` | required, max 20, unique (including closed accounts) |
| `full_name` | required, max 255 |
| `class` | required, `X`, `XI` or `XII` |
| `major_id` | required, existing major ID |
| `group` | required, `1`–`4` |
| `pin` | required, exactly 6 digits (stored hashed) |

```json
{ "nis": "20260004", "full_name": "Dewi Anggraini", "class": "X", "major_id": 3, "group": "2", "pin": "654321" }
```

**201**

```json
{
  "data": {
    "id": 4,
    "account_number": "1000000004",
    "nis": "20260004",
    "full_name": "Dewi Anggraini",
    "class": "X",
    "group": "2",
    "major": { "id": 3, "major_name": "Manajemen Perkantoran" },
    "account_status": "active",
    "balance": 0,
    "created_at": "2026-10-23T06:17:25.000000Z",
    "updated_at": "2026-10-23T06:17:25.000000Z",
    "deleted_at": null
  }
}
```

**422**

```json
{
  "message": "The nis has already been taken. (and 4 more errors)",
  "errors": {
    "nis": ["The nis has already been taken."],
    "full_name": ["The full name field is required."],
    "class": ["The selected class is invalid."],
    "major_id": ["The selected major id is invalid."],
    "pin": ["The pin field must be 6 digits."]
  }
}
```

#### `PUT|PATCH /admin/customers/{id}`

**Who:** Admin. Update any subset of fields. Account number and balance cannot be
changed. To close an account use [deactivate](#post-admincustomersiddeactivate).

| Field | Rules |
| --- | --- |
| `nis` | max 20, unique |
| `full_name` | max 255 |
| `class` | `X`, `XI`, `XII` |
| `major_id` | existing major ID |
| `group` | `1`–`4` |
| `account_status` | `active` or `suspended` only |
| `pin` | exactly 6 digits (PIN reset) |

```json
{ "class": "XI", "account_status": "suspended" }
```

**200** the updated [Customer](#customer).

#### `POST /admin/customers/{id}/deactivate`

**Who:** Admin. Close a graduated student's account: sets `account_status` to
`inactive`, revokes their tokens and soft deletes the row. Transactions and
journals are kept. No body.

**200**

```json
{
  "data": {
    "id": 4,
    "account_number": "1000000004",
    "account_status": "inactive",
    "balance": 0,
    "deleted_at": "2026-10-23T06:17:25.000000Z",
    "...": "other Customer fields"
  }
}
```

---

### Teller: transactions

#### `GET /teller/customers/lookup`

Find the customer at the counter by exact account number (tried first) or NIS.
Closed accounts are not found.

| Query | Rules |
| --- | --- |
| `identifier` | required, max 50 |

**200** a [CustomerSummary](#customersummary)

```json
{
  "data": {
    "id": 1,
    "account_number": "1000000001",
    "nis": "20260001",
    "full_name": "Budi Santoso",
    "class": "X",
    "group": "1",
    "major_name": "Rekayasa Perangkat Lunak",
    "account_status": "active",
    "balance": 500000
  }
}
```

**404** `{ "message": "Nasabah tidak ditemukan." }`

#### `POST /teller/deposits`

Cash deposit (*setor tunai*). In one database transaction: locks the customer
row, increases the balance, records the transaction and writes two journal lines
(Debit 101 Kas / Credit 201 Tabungan Nasabah).

| Field | Rules |
| --- | --- |
| `account_number` | required, an existing account that is not closed |
| `amount` | required, integer, 1 – 100,000,000 |

```json
{ "account_number": "1000000001", "amount": 50000 }
```

**201** a [Transaction](#transaction)

```json
{
  "data": {
    "id": 4,
    "type": "deposit",
    "amount": 50000,
    "balance_before": 500000,
    "balance_after": 550000,
    "created_at": "2026-10-23T06:17:25.000000Z",
    "customer": { "id": 1, "account_number": "1000000001", "full_name": "Budi Santoso" }
  }
}
```

**422** business rules:

| Message | Cause |
| --- | --- |
| `Rekening berstatus suspended dan tidak dapat bertransaksi.` | Account is not `active` |
| `Loket sudah ditutup: laporan harian hari ini sudah dikirim. Transaksi baru tidak dapat dilakukan.` | Teller already submitted today's daily report |

#### `POST /teller/withdrawals`

Cash withdrawal (*tarik tunai*). Same body and response as a deposit; journal is
Debit 201 Tabungan Nasabah / Credit 101 Kas. The remaining balance must be at least
the minimum balance (Rp10.000 by default, `BANK_MIN_BALANCE`).

```json
{ "account_number": "1000000001", "amount": 25000 }
```

**201**

```json
{
  "data": {
    "id": 5,
    "type": "withdrawal",
    "amount": 25000,
    "balance_before": 550000,
    "balance_after": 525000,
    "created_at": "2026-10-23T06:17:25.000000Z",
    "customer": { "id": 1, "account_number": "1000000001", "full_name": "Budi Santoso" }
  }
}
```

**422** in addition to the deposit errors:

```json
{ "message": "Saldo tidak mencukupi. Saldo minimum Rp10.000 harus tetap tersisa; penarikan maksimal Rp515.000." }
```

#### `GET /teller/transactions`

The logged-in teller's own transactions for one day, newest first, as
[Transaction](#transaction) objects. Default `per_page`: 50.

| Query | Description |
| --- | --- |
| `date` | `YYYY-MM-DD`, default today |

---

### Teller: daily report

The end-of-day cash closing report (*Draf Penutupan Kas*). Totals are always
computed by the server from the teller's transactions.

**Submitting closes the counter:** after a report for today is `submitted` or
`approved`, the teller cannot deposit or withdraw for the rest of the day. A
supervisor rejection reopens it.

#### `GET /teller/daily-reports/preview`

The printable draft: live totals and every transaction of the day. Saves nothing.

| Query | Description |
| --- | --- |
| `date` | `YYYY-MM-DD`, today or earlier, default today |

**200** (`report` is the stored [DailyReport](#dailyreport) for that date, or `null`)

```json
{
  "data": {
    "report_date": "2026-10-23",
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "transaction_count": 5,
    "total_deposit": 2300000,
    "total_withdrawal": 25000,
    "net_cash": 2275000,
    "counter_closed": false,
    "report": null,
    "transactions": [
      {
        "id": 1,
        "type": "deposit",
        "amount": 500000,
        "balance_before": 0,
        "balance_after": 500000,
        "created_at": "2026-10-23T06:17:21.000000Z",
        "customer": { "id": 1, "account_number": "1000000001", "full_name": "Budi Santoso" }
      }
    ]
  }
}
```

#### `POST /teller/daily-reports`

Submit (or resubmit after rejection) the report for a day. Any totals sent by the client are ignored.

| Field | Rules |
| --- | --- |
| `date` | optional `YYYY-MM-DD`, today or earlier, default today |

**201** new report, **200** resubmission of a rejected one. Resubmission recalculates the totals and clears the previous review.

```json
{
  "data": {
    "id": 1,
    "report_date": "2026-10-23",
    "transaction_count": 5,
    "total_deposit": 2300000,
    "total_withdrawal": 25000,
    "net_cash": 2275000,
    "status": "submitted",
    "submitted_at": "2026-10-23T06:17:26.000000Z",
    "reviewed_at": null,
    "supervisor_note": null
  }
}
```

**422** `Laporan tanggal 2026-10-23 sudah submitted.` (or `sudah approved.`)

#### `GET /teller/daily-reports`

The teller's own reports, newest date first, as [DailyReport](#dailyreport) objects with `supervisor`. Default `per_page`: 15.

#### `GET /teller/daily-reports/{id}`

One of the teller's own reports. Another teller's report returns **404**.

---

### Teller: cash drawer and transfers

The cash that should be in a teller's drawer is derived from their transactions:

```text
drawer_balance          = cash_in (deposits) − cash_out (withdrawals) − transferred (approved transfers)
available_for_transfer  = drawer_balance − pending (transfers waiting for approval)
```

#### `GET /teller/drawer`

```json
{
  "data": {
    "cash_in": 2300000,
    "cash_out": 25000,
    "transferred": 0,
    "drawer_balance": 2275000,
    "pending": 0,
    "available_for_transfer": 2275000
  }
}
```

#### `GET /teller/cash-transfers`

The teller's own transfers, newest first, as [CashTransfer](#cashtransfer) objects with `supervisor`. Default `per_page`: 15.

#### `POST /teller/cash-transfers`

Request moving cash from the drawer to the main vault. It stays `pending` until a supervisor reviews it.

| Field | Rules |
| --- | --- |
| `amount` | required, integer, 1 – 1,000,000,000, at most `available_for_transfer` |
| `note` | optional, max 1000 |

```json
{ "amount": 2000000, "note": "Setor ke brankas" }
```

**201**

```json
{
  "data": {
    "id": 1,
    "source": "teller_drawer",
    "destination": "main_vault",
    "amount": 2000000,
    "status": "pending",
    "note": "Setor ke brankas",
    "created_at": "2026-10-23T06:17:26.000000Z",
    "reviewed_at": null,
    "supervisor_note": null
  }
}
```

**422** `Kas di laci teller tidak mencukupi. Maksimal yang dapat dipindahkan Rp275.000.`

---

### Supervisor: daily reports

#### `GET /supervisor/daily-reports`

Paginated [DailyReport](#dailyreport) objects with `teller` and `supervisor`, newest date first. Default `per_page`: 15.

| Query | Description |
| --- | --- |
| `status` | `submitted`, `approved`, `rejected` |
| `date` | `YYYY-MM-DD` |
| `teller_id` | Filter by teller |

#### `GET /supervisor/daily-reports/{id}`

One report plus the transactions it summarizes (top-level `transactions`), for verification.

```json
{
  "data": {
    "id": 1,
    "report_date": "2026-10-23",
    "transaction_count": 5,
    "total_deposit": 2300000,
    "total_withdrawal": 25000,
    "net_cash": 2275000,
    "status": "submitted",
    "submitted_at": "2026-10-23T06:17:26.000000Z",
    "reviewed_at": null,
    "supervisor_note": null,
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "supervisor": null
  },
  "transactions": [
    {
      "id": 1,
      "type": "deposit",
      "amount": 500000,
      "balance_before": 0,
      "balance_after": 500000,
      "created_at": "2026-10-23T06:17:21.000000Z",
      "customer": { "id": 1, "account_number": "1000000001", "full_name": "Budi Santoso" }
    }
  ]
}
```

#### `POST /supervisor/daily-reports/{id}/approve`

| Field | Rules |
| --- | --- |
| `note` | optional, max 1000 |

**200** the [DailyReport](#dailyreport) with `status: "approved"`, `reviewed_at` and `supervisor` set. Approved reports are final.

```json
{
  "data": {
    "id": 1,
    "report_date": "2026-10-23",
    "status": "approved",
    "reviewed_at": "2026-10-23T06:17:26.000000Z",
    "supervisor_note": "Sesuai",
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "supervisor": { "id": 2, "full_name": "Supervisor Bank Mini" },
    "...": "other DailyReport fields"
  }
}
```

**422** `Laporan berstatus approved tidak dapat diproses lagi.` Only `submitted` reports can be reviewed.

#### `POST /supervisor/daily-reports/{id}/reject`

| Field | Rules |
| --- | --- |
| `note` | **required**, max 1000 |

**200** the report with `status: "rejected"`. This reopens the teller's counter for that date so they can correct and resubmit. **422** if `note` is missing or the report is not `submitted`.

---

### Supervisor: cash transfers

#### `GET /supervisor/cash-transfers`

Paginated [CashTransfer](#cashtransfer) objects with `teller` and `supervisor`, newest first. Default `per_page`: 15.

| Query | Description |
| --- | --- |
| `status` | `pending`, `approved`, `rejected` |
| `teller_id` | Filter by teller |

#### `GET /supervisor/cash-transfers/{id}`

One transfer plus the teller's current drawer (top-level `drawer`), to check the amount against the cash that should be there.

```json
{
  "data": {
    "id": 1,
    "source": "teller_drawer",
    "destination": "main_vault",
    "amount": 2000000,
    "status": "pending",
    "note": "Setor ke brankas",
    "created_at": "2026-10-23T06:17:26.000000Z",
    "reviewed_at": null,
    "supervisor_note": null,
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "supervisor": null
  },
  "drawer": {
    "cash_in": 2300000,
    "cash_out": 25000,
    "transferred": 0,
    "drawer_balance": 2275000,
    "pending": 2000000,
    "available_for_transfer": 275000
  }
}
```

#### `POST /supervisor/cash-transfers/{id}/approve`

| Field | Rules |
| --- | --- |
| `note` | optional, max 1000 |

Re-checks that the drawer still holds the amount, since withdrawals after the request may have paid cash out.

**200**

```json
{
  "data": {
    "id": 1,
    "amount": 2000000,
    "status": "approved",
    "reviewed_at": "2026-10-23T06:17:26.000000Z",
    "supervisor_note": "Uang diterima di brankas",
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "supervisor": { "id": 2, "full_name": "Supervisor Bank Mini" },
    "...": "other CashTransfer fields"
  }
}
```

**422** `Pemindahan kas berstatus approved tidak dapat diproses lagi.` or `Kas di laci teller tidak mencukupi. ...`

#### `POST /supervisor/cash-transfers/{id}/reject`

| Field | Rules |
| --- | --- |
| `note` | **required**, max 1000 |

**200** the transfer with `status: "rejected"`; its amount becomes available again. **422** if `note` is missing or the transfer is not `pending`.

---

### Audit (Admin, Supervisor)

Read-only. There are no endpoints that create, change or delete transactions or
journal lines (`POST`/`PUT`/`DELETE` return `404` or `405`).

#### `GET /audit/transactions`

All transactions, newest first, as [Transaction](#transaction) objects with `customer` (closed accounts included) and `teller`. Default `per_page`: 15.

| Query | Description |
| --- | --- |
| `from`, `to` | `YYYY-MM-DD`, inclusive |
| `type` | `deposit` or `withdrawal` |
| `teller_id` | Filter by teller |
| `customer_id` | Filter by customer |

#### `GET /audit/transactions/{id}`

One transaction with its two journal lines.

```json
{
  "data": {
    "id": 4,
    "type": "deposit",
    "amount": 50000,
    "balance_before": 500000,
    "balance_after": 550000,
    "created_at": "2026-10-23T06:17:25.000000Z",
    "customer": { "id": 1, "account_number": "1000000001", "full_name": "Budi Santoso" },
    "teller": { "id": 3, "full_name": "Teller Loket 1" },
    "journals": [
      { "id": 8, "transaction_id": 4, "account_code": "201", "account_name": "Tabungan Nasabah", "position": "credit", "amount": 50000, "created_at": "2026-10-23T06:17:25.000000Z" },
      { "id": 7, "transaction_id": 4, "account_code": "101", "account_name": "Kas", "position": "debit", "amount": 50000, "created_at": "2026-10-23T06:17:25.000000Z" }
    ]
  }
}
```

#### `GET /audit/journals`

Journal lines, newest first, as [Journal](#journal) objects with a `transaction` summary. Default `per_page`: 30.

| Query | Description |
| --- | --- |
| `from`, `to` | `YYYY-MM-DD`, inclusive |
| `transaction_id` | Lines of one transaction |
| `account_code` | `101` or `201` |
| `position` | `debit` or `credit` |

```json
{
  "data": [
    {
      "id": 10,
      "transaction_id": 5,
      "account_code": "101",
      "account_name": "Kas",
      "position": "credit",
      "amount": 25000,
      "created_at": "2026-10-23T06:17:25.000000Z",
      "transaction": { "id": 5, "type": "withdrawal", "customer_id": 1, "user_id": 3 }
    }
  ]
}
```

#### `GET /audit/journals/summary`

Debit and credit totals per account (a trial balance). Accepts the same filters
as `/audit/journals`. `is_balanced` is `true` when total debit equals total credit,
which double-entry guarantees.

```json
{
  "data": {
    "accounts": [
      { "account_code": "101", "account_name": "Kas", "total_debit": 2300000, "total_credit": 25000 },
      { "account_code": "201", "account_name": "Tabungan Nasabah", "total_debit": 25000, "total_credit": 2300000 }
    ],
    "total_debit": 2325000,
    "total_credit": 2325000,
    "is_balanced": true
  }
}
```

---

### Customer portal

Everything is scoped to the account that owns the token. Parameters such as
`?customer_id=` are ignored, and another customer's transaction returns `404`.

#### `GET /customer/balance`

```json
{
  "data": {
    "account_number": "1000000001",
    "full_name": "Budi Santoso",
    "account_status": "active",
    "balance": 525000,
    "min_balance": 10000,
    "as_of": "2026-10-23T06:17:26.374723Z"
  }
}
```

#### `GET /customer/transactions`

Account statement (*mutasi rekening*), newest first, as [StatementLine](#statementline) objects. Default `per_page`: 20.

| Query | Description |
| --- | --- |
| `from`, `to` | `YYYY-MM-DD`, inclusive |
| `type` | `deposit` or `withdrawal` |

```json
{
  "data": [
    { "id": 5, "type": "withdrawal", "direction": "out", "description": "Tarik tunai", "amount": 25000, "balance_after": 525000, "created_at": "2026-10-23T06:17:25.000000Z" },
    { "id": 4, "type": "deposit", "direction": "in", "description": "Setor tunai", "amount": 50000, "balance_after": 550000, "created_at": "2026-10-23T06:17:25.000000Z" }
  ]
}
```

#### `GET /customer/transactions/{id}`

One [StatementLine](#statementline). **404** `{ "message": "Data tidak ditemukan." }` if it belongs to someone else.

---

## Objects

Passwords and PINs never appear in any response.

### Staff

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `username` | string | |
| `full_name` | string | |
| `role` | string | `admin`, `supervisor`, `teller` |
| `is_active` | boolean | `false` = access removed |
| `created_at`, `updated_at` | timestamp | |

### Customer

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `account_number` | string | 10 digits, generated |
| `nis` | string | |
| `full_name` | string | |
| `class` | string | `X`, `XI`, `XII` |
| `group` | string | `1`–`4` |
| `major` | object | `{ id, major_name }` |
| `account_status` | string | `active`, `suspended`, `inactive` |
| `balance` | integer | Rupiah |
| `created_at`, `updated_at` | timestamp | |
| `deleted_at` | timestamp or null | Set when the account is closed |

### CustomerSummary

What a teller sees at the counter: `id`, `account_number`, `nis`, `full_name`,
`class`, `group`, `major_name` (string), `account_status`, `balance`.

### Transaction

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `type` | string | `deposit`, `withdrawal` |
| `amount` | integer | Always > 0 |
| `balance_before` | integer | Customer balance before |
| `balance_after` | integer | Customer balance after |
| `created_at` | timestamp | |
| `customer` | object | `{ id, account_number, full_name }` |
| `teller` | object | `{ id, full_name }`, audit endpoints only |
| `journals` | Journal[] | `GET /audit/transactions/{id}` only |

### Journal

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `transaction_id` | integer | Every transaction has exactly two lines |
| `account_code` | string | `101`, `201` |
| `account_name` | string | `Kas`, `Tabungan Nasabah` |
| `position` | string | `debit`, `credit` (one of each per transaction) |
| `amount` | integer | Equals the transaction amount |
| `created_at` | timestamp | |
| `transaction` | object | `{ id, type, customer_id, user_id }`, on `/audit/journals` |

### DailyReport

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `report_date` | date | `YYYY-MM-DD` |
| `transaction_count` | integer | |
| `total_deposit` | integer | |
| `total_withdrawal` | integer | |
| `net_cash` | integer | `total_deposit − total_withdrawal` |
| `status` | string | `submitted`, `approved`, `rejected` |
| `submitted_at` | timestamp | |
| `reviewed_at` | timestamp or null | |
| `supervisor_note` | string or null | |
| `teller` | object | `{ id, full_name }`, supervisor endpoints |
| `supervisor` | object or null | `{ id, full_name }` of the reviewer |

### CashTransfer

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `source` | string | Always `teller_drawer` |
| `destination` | string | Always `main_vault` |
| `amount` | integer | |
| `status` | string | `pending`, `approved`, `rejected` |
| `note` | string or null | From the teller |
| `created_at` | timestamp | When it was requested |
| `reviewed_at` | timestamp or null | |
| `supervisor_note` | string or null | |
| `teller` | object | `{ id, full_name }`, supervisor endpoints |
| `supervisor` | object or null | `{ id, full_name }` of the reviewer |

### StatementLine

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `type` | string | `deposit`, `withdrawal` |
| `direction` | string | `in`, `out` |
| `description` | string | `Setor tunai`, `Tarik tunai` |
| `amount` | integer | |
| `balance_after` | integer | Balance after this line |
| `created_at` | timestamp | |

---

## Workflows

### Serving a customer at the counter (Teller)

1. `GET /teller/customers/lookup?identifier=1000000001` to confirm name, status and balance.
2. `POST /teller/deposits` or `POST /teller/withdrawals` with `account_number` and `amount`.
3. Print a receipt from the response (`balance_before`, `balance_after`).

### End of day

1. Teller: `GET /teller/daily-reports/preview`, print it and count the cash.
2. Teller: `POST /teller/daily-reports`. The counter is now closed for today.
3. Supervisor: `GET /supervisor/daily-reports?status=submitted`, then `GET /supervisor/daily-reports/{id}` to compare against the transactions.
4. Supervisor: `POST .../approve`, or `POST .../reject` with a note. A rejection reopens the counter; the teller fixes things and submits again.

### Moving cash to the vault

1. Teller: `GET /teller/drawer`, then `POST /teller/cash-transfers` with an amount up to `available_for_transfer`.
2. Supervisor: `GET /supervisor/cash-transfers?status=pending`, then `GET /supervisor/cash-transfers/{id}` to see the drawer.
3. Supervisor: `POST .../approve` once the cash is in the vault, or `POST .../reject` with a note.

### Graduation

1. Optional: Teller pays out the remaining balance with `POST /teller/withdrawals` (the minimum balance still applies).
2. Admin: `POST /admin/customers/{id}/deactivate`. The student can no longer log in; history stays available under `/customers?trashed=with` and in the audit endpoints.
