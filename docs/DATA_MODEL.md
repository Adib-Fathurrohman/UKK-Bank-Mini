# Data Model (frontend view)

The data a frontend needs to understand, as exposed by the API resources
(`app/Http/Resources`) and defined in the migrations and models. Internal tables
(cache, jobs, sessions, tokens) are left out.

All money values are **integer Rupiah**. All timestamps are ISO 8601 **UTC**; dates
are `YYYY-MM-DD` in Asia/Jakarta.

## Entity overview

```text
Major 1 ──── * Customer 1 ──── * Transaction * ──── 1 DailyReport (shift) * ──── 1 User (teller)
                                    │  │                 │  │
                                    │  └─ 0..1 correction│  ├── * CashCount (opening / closing)
                                    │     (self link)    │  └── 0..1 CashTransfer ──── 1 User (supervisor)
                                    └── 2 AccountingJournal
User (supervisor) 1 ──── * DailyReport (reviewed)      Setting * ──── 1 User (admin, updated_by)
User (supervisor) 1 ──── * Transaction (authorized corrections)
WithdrawalAuthorization * ── 1 User (teller), 1 Customer, 1 DailyReport; 0..1 ── 1 Transaction (consumed)
```

Foreign keys use `ON DELETE RESTRICT`: anything with history cannot be deleted.

---

## Staff (`users`)

Administrators, supervisors and tellers. Resource: `StaffResource`.

| Field | Type | Nullable | Meaning |
| --- | --- | --- | --- |
| `id` | integer | no | Identifier used in `/staff/{id}` |
| `username` | string | no | Login name; letters, digits, `-`, `_`; unique |
| `full_name` | string | no | Display name |
| `class` | string ≤ 20 | yes | Teller's school class (e.g. `XI AK 1`); tellers are students on duty |
| `phone` | string ≤ 20 | yes | WhatsApp number |
| `role` | enum | no | `admin`, `supervisor`, `teller` |
| `is_active` | boolean | no | `false` = blocked: cannot log in, tokens revoked |
| `created_at`, `updated_at` | timestamp | no | |

Never returned: `password`, `remember_token`.

## Customer (`customers`)

Students' savings accounts (nasabah). Resources: `CustomerResource` (full),
`CustomerSummaryResource` (teller lookup), `CustomerCardResource` (card).

| Field | Type | Nullable | Meaning |
| --- | --- | --- | --- |
| `id` | integer | no | Identifier used in `/customers/{id}` |
| `account_number` | string | no | Generated, unique, never editable. Format below |
| `nis` | string ≤ 20 | no | Student number; unique; used by the teller lookup and by the customer to activate the app |
| `full_name` | string | no | |
| `email` | string ≤ 255 | yes | Registered email (lowercase, unique). Required for new accounts; null for older ones until an admin adds it. Not editable after activation. Not in the teller lookup |
| `activation_status` | `pending` \| `activated` | no | Derived from `activated_at`: `pending` until the customer activates the app with a Firebase account ([CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)) |
| `activated_at` | timestamp | yes | When the app was activated |
| `pin_set` | boolean | no | Whether the customer has created a PIN (in the app, after activation; false again after an admin reset). Also in the teller lookup |
| `class` | enum | no | `X`, `XI`, `XII` |
| `group` | enum | no | `1`–`4` (the parallel class, e.g. XI RPL **2**) |
| `major` | object | no | `{id, code, major_name}` (lookup returns only `major_name`; card returns `{code, major_name}`) |
| `account_status` | enum | no | `active`, `suspended`, `inactive` (see below) |
| `balance` | integer | no | Current balance; never negative (DB CHECK) |
| `created_at`, `updated_at` | timestamp | no | |
| `deleted_at` | timestamp | yes | Set when the account is closed (graduated) |
| `pin_locked_until` | timestamp | yes | **Lookup only.** The PIN is locked until then: unlock, approval and withdrawals are refused (shown only while in the future) |
| `qr_payload` | string | no | **Card only.** `BANKMINI:{account_number}`; render as a QR code |

Never returned: `pin` (hash), `pin_failed_attempts`, `firebase_uid` (the bound Firebase
user, unique), `email_verified_at`.

Database rules: `email` and `firebase_uid` are unique;
`CHECK (activated_at IS NULL OR firebase_uid IS NOT NULL)`; `pin` is nullable (bcrypt) and
`CHECK (pin IS NULL OR activated_at IS NOT NULL)`, so only an activated customer can have
a PIN.

### Account number format

`{MAJOR CODE}-{SCHOOL YEAR}-{SERIAL}`, e.g. `RPL-2026-001`.

- Major code from `majors.code`: AK, BR, BD, MP, DKV, RPL, TKJ, LPS (seeded).
- School year: the year in which the July–June school year started at the moment the
  account was opened (October 2026 → `2026`; June 2027 → `2026`; July 2027 → `2027`).
- Serial: at least 3 digits, zero-padded, restarting per major and school year, never
  reused (closed accounts count). After `999` comes `1000`.
- Changing a customer's major later does not change the number.

### Account status

| Status | Login | Deposit / withdrawal | Correction | Lookup | How it is set |
| --- | :-: | :-: | :-: | :-: | --- |
| `active` | ✓ | ✓ | ✓ | ✓ | On creation; admin edit |
| `suspended` | ✓ | — | ✓ | ✓ | Admin edit |
| `inactive` | — | — | — | — | Only by deactivation (graduation), which also soft-deletes the account |

"Login" means the app login, which also needs `activation_status = activated`.
Activation is independent of `account_status`: a pending account receives deposits at the
counter, but no withdrawal is possible until the customer has activated the app and
created a PIN.

## Major (`majors`)

`{id, code, major_name}` from `GET /majors` (sorted by name). Read-only through the
API.

## Daily report / shift (`daily_reports`)

One teller's shift for one day; it is also the end-of-day report. Resource:
`DailyReportResource`.

| Field | Type | Nullable | Meaning |
| --- | --- | --- | --- |
| `id` | integer | no | Identifier |
| `report_date` | date | no | Shift date; unique per teller |
| `status` | enum | no | See below |
| `opening_balance` | integer | no | Counted cash at opening (saldo awal) |
| `opened_at` | timestamp | yes | |
| `transaction_count` | integer | no | Number of transactions (including corrections) |
| `total_deposit` | integer | no | Deposits minus deposit corrections |
| `total_withdrawal` | integer | no | Withdrawals minus withdrawal corrections |
| `system_balance` | integer | yes | `opening_balance + total_deposit − total_withdrawal` |
| `physical_balance` | integer | yes | Counted cash at closing (uang fisik) |
| `difference` | integer | yes | `physical_balance − system_balance`; `0` after a successful close |
| `submitted_at`, `reviewed_at` | timestamp | yes | |
| `supervisor_note` | string | yes | Reason given on hold/reject, or note on approve |
| `teller` | object | — | `{id, full_name, class}`; only on supervisor endpoints |
| `supervisor` | object | yes | `{id, full_name}` of the reviewer |
| `opening_counts`, `closing_counts` | array | — | `[{denomination, quantity, subtotal}]`, largest note first; only on shift/detail endpoints |
| `cash_transfer` | object | yes | `{id, amount, transferred_at}` after approval |

For an **active** shift (`open`/`rejected`) the totals and `system_balance` are
computed live. For a `rejected` shift, `physical_balance`, `difference` and
`closing_counts` still show the previous (rejected) count until it is closed again.

### Report statuses

| Status | Meaning | Teller can transact | Next |
| --- | --- | --- | --- |
| `open` | Shift running | Yes, if `report_date` is today | `submitted` (close) |
| `submitted` | Closed with matching cash; waiting for the supervisor | No | `approved`, `on_hold`, `rejected` |
| `on_hold` | Supervisor is waiting for something (note explains) | No | `approved`, `rejected` |
| `rejected` | Sent back; the shift is active again for correction and re-closing | Yes, if `report_date` is today; corrections any day | `submitted` |
| `approved` | Final; cash moved to the vault | No | — |

Indonesian labels used in PDFs: Berjalan, Menunggu verifikasi, Ditahan, Disetujui,
Ditolak.

## Cash count (`cash_counts`)

Exposed only inside `opening_counts` / `closing_counts`. Denominations: 100000, 50000,
20000, 10000, 5000, 2000, 1000, 500, 200, 100. Only quantities > 0 are stored.

## Transaction (`transactions`)

Immutable money movement. Resource: `TransactionResource` (staff),
`StatementLineResource` (customer).

| Field | Type | Nullable | Meaning |
| --- | --- | --- | --- |
| `id` | integer | no | Transaction number (printed on receipts) |
| `type` | enum | no | See below |
| `type_label` | string | no | Indonesian label |
| `amount` | integer | no | Always > 0 (DB CHECK) |
| `amount_in_words` | string | no | Terbilang, e.g. `seratus dua puluh lima ribu rupiah` |
| `balance_before`, `balance_after` | integer | no | Customer balance around this transaction |
| `created_at` | timestamp | no | When it was recorded |
| `daily_report_id` | integer | no | The shift it belongs to |
| `corrects_transaction_id` | integer | yes | Corrections only: the reversed transaction |
| `note` | string | yes | Corrections only: the reason |
| `authorized_by` | object | yes | Corrections only: `{id, full_name}` of the supervisor (when loaded) |
| `corrected_by` | object | yes | Ordinary transactions: `{id, type, created_at}` of the correction (when loaded) |
| `customer` | object | — | `{id, account_number, full_name}` |
| `teller` | object | — | `{id, full_name}`; audit endpoints |
| `journals` | array | — | `GET /audit/transactions/{id}` only |
| `withdrawal_authorization` | object | — | Withdrawals approved in the mobile app: `{id, method: "mobile", approved_at}` (on `complete` and the audit detail) |

Customer statement line: `{id, type, direction, description, amount, balance_after,
created_at}`; `direction` is `in` or `out`.

Never returned: `idempotency_key`.

### Transaction types

| Type | Label | Balance | Journal (debit / credit) | Created by |
| --- | --- | --- | --- | --- |
| `deposit` | Setor tunai | + amount | 101 Kas / 201 Tabungan | `POST /teller/deposits` |
| `withdrawal` | Tarik tunai | − amount | 201 Tabungan / 101 Kas | `POST /teller/withdrawal-authorizations/{id}/complete` (mobile approval) or `POST /teller/withdrawals` (PIN at the terminal) |
| `deposit_correction` | Koreksi setoran | − amount | 201 Tabungan / 101 Kas | Correction of a deposit |
| `withdrawal_correction` | Koreksi penarikan | + amount | 101 Kas / 201 Tabungan | Correction of a withdrawal |

### Transaction status

**Transactions have no status field.** They are recorded or not recorded (failures save
nothing). A transaction is "corrected" when another transaction references it
(`corrected_by`); each can be corrected only once.

## Accounting journal (`accounting_journals`)

Two lines per transaction, read-only. `{id, transaction_id, account_code, account_name,
position, amount, created_at}` plus a `transaction` summary on `/audit/journals`.

| Field | Values |
| --- | --- |
| `account_code` | `101` (Kas), `201` (Tabungan Nasabah) |
| `position` | `debit`, `credit` (one of each per transaction) |

## Cash transfer (`cash_transfers`)

Cash moved from a teller's drawer to the main vault, created automatically when a
supervisor approves a report with counted cash > 0. `{id, source: "teller_drawer",
destination: "main_vault", amount, transferred_at, daily_report: {id, report_date,
teller}, approved_by: {id, full_name}}`. Immutable.

## Withdrawal authorization (`withdrawal_authorizations`)

A withdrawal the teller started and the customer approves in the mobile app. Resources:
`WithdrawalAuthorizationResource` (teller), `CustomerWithdrawalAuthorizationResource`
(customer). Never deleted; finished rows cannot be changed.

| Field | Type | Nullable | Meaning |
| --- | --- | --- | --- |
| `id` | string (ULID) | no | `public_id` column; used in URLs. The integer key is never exposed |
| `status` | enum | no | See below |
| `status_label` | string | no | Indonesian label |
| `amount`, `amount_in_words` | integer, string | no | Bound amount (DB CHECK > 0) |
| `customer` | object | — | Teller view: `{id, account_number, full_name, class}` |
| `account_number`, `balance`, `balance_after` | — | — | Customer view; `balance_after` is a preview, `null` once not open |
| `teller` | object | — | Customer view: `{full_name}` |
| `daily_report_id` | integer | no | Teller view: the shift it belongs to |
| `created_at`, `expires_at` | timestamp | no | Customer must decide before `expires_at` (120 s) |
| `approved_at`, `approval_expires_at` | timestamp | yes | Teller must complete before `approval_expires_at` (120 s after approval) |
| `rejected_at`, `cancelled_at`, `cancel_reason` | timestamp, string | yes | Teller view. `cancel_reason`: `teller`, `shift_closed` |
| `consumed_at` | timestamp | yes | When the withdrawal was recorded |
| `transaction_id` | integer | yes | Teller view: the withdrawal (unique: one transaction per request) |
| `server_time` | timestamp | no | Server clock, for countdowns |

There is no PIN column.

### Authorization statuses

| Status | Label | Open | Next |
| --- | --- | :-: | --- |
| `pending` | Menunggu persetujuan nasabah | ✓ | `approved`, `rejected`, `cancelled`, `expired` |
| `approved` | Disetujui nasabah | ✓ | `consumed`, `cancelled`, `expired` |
| `consumed` | Selesai | | — |
| `rejected` | Ditolak nasabah | | — |
| `cancelled` | Dibatalkan | | — |
| `expired` | Kedaluwarsa | | — |

At most one open request per customer and per teller.

## Settings (`settings`)

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `min_balance` | integer 0–1,000,000 | 10000 | Balance that must remain after a withdrawal |
| `qr_scanner_mode` | `webcam`, `usb`, `disabled` | `webcam` | How the teller UI should read QR cards (informational) |
| `withdrawal_verification` | `mobile`, `terminal`, `both` | `mobile` | Approval in the customer's app, PIN at the teller terminal, or either |

## User / role relationships

- Staff and customers are **different tables and models**. A customer is not a `users`
  row and has no `role`.
- Each staff member has exactly one `role`.
- A teller owns shifts (`daily_reports.teller_id`), transactions
  (`transactions.user_id`) and withdrawal requests (`withdrawal_authorizations.teller_id`).
- A customer approves or rejects only requests with their `customer_id`.
- A customer is bound to at most one Firebase user (`firebase_uid`) and a Firebase user to
  at most one customer; customers authenticate through it, never through a `users` row.
- A supervisor appears as reviewer (`daily_reports.supervisor_id`), approver
  (`cash_transfers.approved_by`) and correction authorizer
  (`transactions.authorized_by`).
- An admin appears as `settings.updated_by`.
