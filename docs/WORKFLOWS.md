# Business Workflows

What the backend actually does for each workflow. Source: controllers in
`app/Http/Controllers/API`, services in `app/Services` (`TransactionService`,
`DailyReportService`, `PinService`, `WithdrawalAuthorizationService`, `CustomerService`, `SettingsService`,
`StaffImportService`, `ReportService`, `PdfReportService`), form requests, and the
feature tests in `tests/Feature`.

Each workflow lists: actor, preconditions, user action, backend operation,
validation, database effect, API response, failure cases. Endpoint details and
examples are in [API.md](API.md).

---

## 1. Authentication

**1.1 Login**

| | |
| --- | --- |
| Actor | Any staff member or customer |
| Preconditions | Account exists; staff `is_active`; customer not closed |
| User action | Submits username+password or NIS/account number+PIN |
| Backend operation | `POST /login` → `LoginController` picks staff or customer login → credential check → `createToken()` |
| Validation | Field rules ([AUTH.md](AUTH.md)); staff: bcrypt check, active; customer: PIN through `PinService` (shared lock), not inactive/soft-deleted; 5 attempts/min per identifier+IP |
| Database effect | Inserts a row in `personal_access_tokens`. Customer: a wrong PIN increments `pin_failed_attempts` (3rd → `pin_locked_until` = now + 15 min); a correct one resets it |
| API response | `200 {data:{actor, token, token_type, user|customer}}` |
| Failure cases | `422` wrong credentials / blocked / missing fields (remaining PIN attempts are not revealed); `422 "Terlalu banyak percobaan PIN salah. Coba lagi pukul HH:MM."` while locked; `429` rate limit |

**1.2 Logout:** `POST /staff/logout` or `/customer/logout` deletes the current token
row. `200 {"message":"Berhasil keluar."}`. Failure: `401` if the token is already
invalid.

**1.3 Current user:** `GET /staff/me` / `GET /customer/me`. No database writes.
Failure: `401`, `403`.

---

## 2. Account lookup (teller)

| | |
| --- | --- |
| Actor | Teller |
| Preconditions | Logged in as an active teller. A shift is **not** required for lookup |
| User action | Scans a customer card QR (`BANKMINI:{account_number}`) or types an account number or NIS |
| Backend operation | `GET /teller/customers/lookup?identifier=` → strips the `BANKMINI:` prefix if present → finds a non-closed customer by exact `account_number`, otherwise by exact `nis` |
| Validation | `identifier` required, string, max 60 |
| Database effect | None |
| API response | `200` CustomerSummary: id, account_number, nis, full_name, class, group, major_name, account_status, balance, pin_locked_until (only while in the future) |
| Failure cases | `404 "Nasabah tidak ditemukan."` (unknown or closed); `422` missing identifier; `403` non-teller |

---

## 3. Account management (administrator)

**3.1 Open an account**

| | |
| --- | --- |
| Actor | Admin |
| Preconditions | Major exists (`GET /majors`) |
| User action | Fills NIS, name, class, major, group, initial PIN |
| Backend operation | `POST /admin/customers` → `CustomerService::create` generates `{CODE}-{YEAR}-{NNN}` (school year July–June, serial per major and year, including closed accounts), retries up to 3 times on a duplicate-number race |
| Validation | `nis` unique (incl. closed), class `X/XI/XII`, group `1–4`, major exists, PIN 6 digits |
| Database effect | Inserts `customers` row: status `active`, balance 0, PIN hashed |
| API response | `201` Customer |
| Failure cases | `422` validation; `403` non-admin |

**3.2 Edit / suspend / reset PIN**

| | |
| --- | --- |
| Actor | Admin |
| Preconditions | Account not closed (closed accounts give `404`) |
| User action | Changes NIS, name, class, major, group, status (`active`/`suspended`) or sets a new PIN |
| Backend operation | `PATCH /admin/customers/{id}`; if `pin` is present, also resets `pin_failed_attempts` and `pin_locked_until` |
| Validation | Partial; NIS unique ignoring self; status only `active`/`suspended` |
| Database effect | Updates `customers`. Account number and balance never change (changing the major does not change the account number) |
| API response | `200` Customer |
| Failure cases | `422` (e.g. `account_status: inactive`); `404`; `403` |

Suspending does not revoke the customer's tokens (suspended customers may still log in
and view).

**3.3 Graduate (close) an account**

| | |
| --- | --- |
| Actor | Admin |
| Preconditions | Account not already closed |
| User action | Confirms deactivation |
| Backend operation | `POST /admin/customers/{id}/deactivate` → in one DB transaction: status `inactive`, delete all customer tokens, soft delete |
| Validation | None (no balance check) |
| Database effect | `customers.account_status = inactive`, `deleted_at` set; tokens deleted. Transactions and journals untouched |
| API response | `200` Customer with `deleted_at` |
| Failure cases | `404` already closed; `403` |

Closed accounts remain readable via `GET /customers?trashed=with|only`,
`GET /customers/{id}`, the statement PDF and the audit log. **Restoring a closed
account: not currently implemented.** Paying out the remaining balance before closing
must be done by a teller first; the minimum-balance rule still applies.

**3.4 Card data:** `GET /admin/customers/{id}/card` returns account number, NIS, name,
class, group, major code/name, status and `qr_payload`. No database writes. The QR
image itself is not generated by the backend.

**3.5 Browse accounts (admin, supervisor):** `GET /customers` with `search`,
`major_id`, `class`, `group`, `status`, `trashed`; `GET /customers/{id}` (closed ones
too). No writes.

---

## 4. Shift opening (teller)

| | |
| --- | --- |
| Actor | Teller |
| Preconditions | No active shift (`open`/`rejected`) for this teller; no shift with today's date yet |
| User action | Counts the drawer per denomination |
| Backend operation | `POST /teller/shift/open` → `DailyReportService::open` (locks the teller row) |
| Validation | `denominations` present, keys in 100000…100, quantities integer 0–100000 |
| Database effect | Inserts `daily_reports` (status `open`, `report_date` today, `opening_balance` = Σ denomination×quantity, `opened_at`); inserts `cash_counts` rows (stage `opening`, only quantities > 0) |
| API response | `201` DailyReport with live totals (0) and `opening_counts` |
| Failure cases | `422 "Shift tanggal X masih berjalan..."`, `422 "Shift hari ini sudah ditutup..."`, `422` invalid denominations; `403` |

`GET /teller/shift` returns the active shift with live totals, `can_transact`
(`report_date` is today) and its transactions, or `{data:null, can_transact:false,
closed_today}`.

---

## 5. Deposit (setor tunai)

| | |
| --- | --- |
| Actor | Teller |
| Preconditions | Teller has an active shift dated today; customer `active` and not closed |
| User action | Enters account number (from lookup) and amount; confirms |
| Backend operation | `POST /teller/deposits` → optional idempotency replay check → DB transaction: lock teller, lock active shift, replay check again, lock customer, check status, balance += amount, insert transaction, insert 2 journal lines |
| Validation | `account_number` exists and not closed; `amount` integer 1–100,000,000; `Idempotency-Key` format if sent |
| Database effect | `customers.balance` increased; `transactions` row (type `deposit`, `balance_before/after`, `daily_report_id`, `user_id`, `idempotency_key`); `accounting_journals`: Debit 101 Kas, Credit 201 Tabungan |
| API response | `201` Transaction (incl. `amount_in_words`). Repeated key + same request: `200` with the original transaction |
| Failure cases | `422` validation; `422 "Belum ada shift yang dibuka..."`; `422 "Shift hari ini sudah ditutup..."`; `422 "Shift tanggal X belum ditutup..."`; `422 "Rekening berstatus ditangguhkan..."`; `422` idempotency conflict; `403`; `500` (fully rolled back) |

---

## 6. Withdrawal (tarik tunai)

How the customer authorizes a withdrawal depends on the `withdrawal_verification`
setting: `mobile` (default) uses **6A**, `terminal` uses **6B**, `both` allows either.
Details and the security design: [PIN_VERIFICATION.md](PIN_VERIFICATION.md).

**6A. Approved by the customer in the mobile app (default)**

| | |
| --- | --- |
| Actor | Teller (creates and completes), customer (approves in the app with their PIN) |
| Preconditions | As deposit; mode `mobile`/`both`; account `active`; PIN not locked; balance − amount ≥ minimum balance; no open request of this teller and none for this customer |
| User action | Teller enters the amount and sends the request → customer opens the app, checks amount, teller and balance after, enters the PIN (or rejects) → teller sees "approved" and completes |
| Backend operation | 1. `POST /teller/withdrawal-authorizations`: DB transaction locking teller, shift, customer; pre-checks; insert `pending` row (`expires_at` = now + 120 s). 2. `POST /customer/withdrawal-authorizations/{id}/approve`: `PinService::verify` (own DB transaction), then compare-and-set `pending → approved` (`approval_expires_at` = now + 120 s). 3. `POST /teller/withdrawal-authorizations/{id}/complete`: one DB transaction locking teller, shift, the request (must be `approved`, unexpired, same shift), customer; balance −= amount; insert transaction; `approved → consumed` with `transaction_id`; journal |
| Validation | `account_number`, `amount` as deposit; `pin` exactly 6 digits on approve |
| Database effect | `withdrawal_authorizations` row through its states; on completion the same effects as 6B plus `consumed_at` and `transaction_id`. PIN counter as in 6B (wrong PIN on approve leaves the request `pending`) |
| API response | Create `201` (pending); approve `200` (approved); complete `201` Transaction with `withdrawal_authorization`, `200` with the same transaction when repeated |
| Failure cases | `422` open request exists (teller or customer); `422` mode disabled; `422` wrong PIN / locked; `422` status not usable (`status` in body: rejected, cancelled, expired, consumed, still pending); `422` approval expired / other shift; all deposit and minimum-balance failures; `404` another teller's or customer's request; `403` staff on approve, customer on create; `500` on complete fully rolled back (request stays `approved`) |

Other transitions: customer rejects (`pending → rejected`); teller cancels
(`pending|approved → cancelled`, reason `teller`); shift close cancels open requests
(reason `shift_closed`); 120 s without action → `expired` (applied on read and by the
scheduled `bank:expire-withdrawal-authorizations`, every minute).

**6B. PIN typed at the teller terminal (mode `terminal` or `both`)**

| | |
| --- | --- |
| Actor | Teller, with the customer typing the PIN |
| Preconditions | As deposit; mode `terminal`/`both`; PIN not locked; balance − amount ≥ minimum balance |
| User action | Enters amount; customer types the 6-digit PIN on the confirmation screen |
| Backend operation | `POST /teller/withdrawals` → mode check → idempotency replay check → **PIN verification first** (own DB transaction: lock customer, check lock, compare hash, update counter) → then the same transaction as a deposit with balance −= amount and the minimum-balance check |
| Validation | Deposit rules + `pin` exactly 6 digits |
| Database effect | On success: balance decreased; `transactions` (type `withdrawal`); journal Debit 201 / Credit 101. A correct PIN resets the PIN counter (even if the withdrawal then fails for another reason). On a wrong PIN: `pin_failed_attempts` +1 (saved even though the request fails); on the 3rd: counter 0 and `pin_locked_until` = now + 15 min |
| API response | `201` Transaction; `200` replay (PIN not re-checked) |
| Failure cases | `422 "Penarikan harus disetujui nasabah melalui aplikasi..."` in `mobile` mode; `422 "PIN salah. Sisa percobaan: N."` (+`remaining_attempts`); `422 "PIN diblokir sementara ... pukul HH:MM."` (+`pin_locked_until`); `422 "Saldo tidak mencukupi. Saldo minimum RpX harus tetap tersisa; penarikan maksimal RpY."`; all deposit failures |

Order matters in 6B: the PIN is checked before the shift, status and balance, so a
wrong PIN counts even when the withdrawal would fail for another reason. 6A checks
those before the customer is asked.

The PIN lock is shared by login, 6A approval, 6B and PIN change.

---

## 7. Correction (transaksi koreksi)

| | |
| --- | --- |
| Actor | Teller, authorized by a supervisor |
| Preconditions | Original is the teller's own `deposit`/`withdrawal`; not yet corrected; its shift is still active (`open` or `rejected`) and is the teller's active shift |
| User action | Chooses the transaction, enters a reason; supervisor types username + password |
| Backend operation | `POST /teller/transactions/{id}/correct` → validate supervisor credentials → idempotency check → DB transaction: lock teller, lock shift (must be the original's), lock original, check not corrected, lock customer, check status (`active` or `suspended`), apply the reverse amount, insert correction, insert reverse journal lines |
| Validation | `reason` ≤ 255; supervisor exists, role supervisor, active, password matches; ownership (`404` otherwise); 10 requests/min |
| Database effect | Balance moves back by the full original amount; `transactions` row (type `deposit_correction` or `withdrawal_correction`, `corrects_transaction_id`, `authorized_by`, `note` = reason); reverse journal pair |
| API response | `201` Transaction with `corrects`/`authorized_by`; `200` replay |
| Failure cases | `422 errors.supervisor_password`; `422 "Transaksi ini sudah pernah dikoreksi."`; `422 "Transaksi koreksi tidak dapat dikoreksi lagi."`; `422 "Koreksi hanya dapat dilakukan pada shift tempat transaksi asli dicatat..."`; `422 "Saldo nasabah sudah tidak mencukupi untuk membatalkan setoran ini."`; `404`; `429` |

Partial corrections are not implemented: a correction always reverses the full
amount; the teller then records the correct amount as a new transaction.

---

## 8. Shift closing (penutupan kas)

| | |
| --- | --- |
| Actor | Teller |
| Preconditions | Teller has an active shift (`open` or `rejected`, any date) |
| User action | Counts the drawer per denomination |
| Backend operation | `POST /teller/shift/close` → lock teller and shift → compute totals from the shift's transactions (net of corrections) → system balance = opening + deposits − withdrawals → compare with the counted total |
| Validation | Denomination rules; physical total must equal the system balance |
| Database effect | On match: `daily_reports` snapshot (counts, totals, `system_balance`, `physical_balance`), status `submitted`, `submitted_at`, review fields cleared; previous closing `cash_counts` replaced; open withdrawal requests of the shift → `cancelled` (`shift_closed`). On mismatch: nothing |
| API response | `200` DailyReport (`difference` 0, `closing_counts`) |
| Failure cases | `422` cash mismatch with `system_balance`, `physical_balance`, `difference`; `422 "Belum ada shift yang dibuka..."` |

After closing, deposits and withdrawals for that day are refused until a supervisor
rejects the report; a new shift can only be opened on a later date. The daily report
PDF (`GET /teller/daily-reports/{id}/export`) is available only for `submitted`,
`on_hold` and `approved` reports.

---

## 9. Transaction history

| View | Actor | Endpoint | Scope / filters |
| --- | --- | --- | --- |
| Today's work | Teller | `GET /teller/transactions?date=` | Own transactions for one date (default today), newest first, 50/page, with `corrected_by` and `authorized_by` |
| Current shift | Teller | `GET /teller/shift` | Transactions of the active shift, oldest first |
| Shift history | Teller | `GET /teller/daily-reports`, `/{id}` | Own shifts; detail includes the shift's transactions |
| Audit log | Admin, Supervisor | `GET /audit/transactions` | All transactions; filters `from`, `to` (date or date-time), `teller`, `customer`, `type`, `teller_id`, `customer_id` |
| One transaction | Admin, Supervisor | `GET /audit/transactions/{id}` | With journal lines, correction links |
| Customer statement | Customer | `GET /customer/transactions` | Own lines; `from`, `to`, `type`; 20/page |
| Statement PDF | Admin, Supervisor / Customer | `GET /customers/{id}/statement`, `GET /customer/statement/export` | Opening balance, lines with running balance, totals |

All read-only. Receipts: `GET /teller/transactions/{id}/receipt` (own, PDF).

---

## 10. Supervisor monitoring

**10.1 Review a daily report**

| | |
| --- | --- |
| Actor | Supervisor |
| Preconditions | Report status `submitted` (approve/hold/reject) or `on_hold` (approve/reject) |
| User action | Opens pending reports, compares the counts and transactions with the slips and cash, decides |
| Backend operation | `GET /supervisor/daily-reports?status=submitted` → `GET /supervisor/daily-reports/{id}` → `POST .../approve` / `.../hold` / `.../reject` (each locks the report) |
| Validation | `note` ≤ 1000, required for hold and reject; status must allow the decision; reject refused while the teller has another active shift |
| Database effect | `daily_reports.status`, `supervisor_id`, `reviewed_at`, `supervisor_note`. Approve also inserts `cash_transfers` (amount = `physical_balance`, if > 0). Reject makes the shift active again for the teller |
| API response | `200` DailyReport (approve includes `cash_transfer`) |
| Failure cases | `422 "Laporan berstatus \"...\" tidak dapat diproses."`; `422 "Laporan ini sudah ditahan."`; `422 "Teller sedang menjalankan shift lain..."`; `422` missing note; `404`; `403` |

Approved reports are final (model guard). Bulk approval is not implemented.

**10.2 Vault ledger:** `GET /supervisor/cash-transfers` (filters `from`, `to`,
`teller_id`; `total_amount` for the filter) and `GET /supervisor/cash-transfers/{id}`.
Read-only. Moving cash out of the vault (e.g. giving tellers opening cash) is **not
currently implemented**; opening balances are just counted by the teller.

**10.3 Audit and accounting:** `GET /audit/transactions`, `/audit/journals`,
`/audit/journals/summary` (trial balance with `is_balanced`), PDFs via `/export`.
Read-only. Also available to admins.

**10.4 Staff overview:** `GET /staff?role=teller` (e.g. for the report filter).

---

## 11. Administrative management

| Workflow | Endpoint | Database effect | Notable failures |
| --- | --- | --- | --- |
| Create teller/supervisor | `POST /admin/staff` | `users` row (password hashed, `is_active` true) | `422` (role `admin` refused, duplicate username) |
| Edit staff | `PATCH /admin/staff/{id}` | `users` update (incl. password, role teller↔supervisor) | `403` for admin accounts |
| Block | `POST /admin/staff/{id}/block` | `is_active` false, all tokens deleted | `403` admin |
| Unblock | `POST /admin/staff/{id}/unblock` | `is_active` true | `403` admin |
| Delete | `DELETE /admin/staff/{id}` | Row and tokens deleted if nothing references it | `409` when transactions, reports, approvals or settings changes reference it; `403` admin |
| Import | `POST /admin/staff/import` (multipart `file`) | All rows inserted in one DB transaction; missing passwords generated (returned once) | `422` keyed `rows.{i}.{field}` (row i+2 in the sheet), nothing inserted |
| Settings | `GET /settings`, `PATCH /admin/settings` | `settings` rows (`key`, `value`, `updated_by`) | `422` (min_balance 0–1,000,000; scanner mode `webcam`/`usb`/`disabled`; withdrawal verification `mobile`/`terminal`/`both`) |
| Reports | `GET /audit/journals/export`, `/audit/transactions/export`, `/reports/annual?school_year=` | None (PDF) | `422` invalid filters/year |

Admin accounts themselves (including password changes) cannot be managed through the
API. Majors cannot be managed through the API.

---

## 12. Mobile customer workflows

| Workflow | Endpoint | Behavior |
| --- | --- | --- |
| Login | `POST /login` `{identifier, pin}` | Token; suspended accounts may log in. Wrong PINs count toward the shared PIN lock |
| Overview | `GET /customer/balance` | `account_number`, `full_name`, `account_status`, `balance`, `min_balance`, `as_of` |
| Profile | `GET /customer/me` | Customer with major |
| History | `GET /customer/transactions?from=&to=&type=&page=` | Lines with `direction` (`in`/`out`), `description` (Indonesian label), `amount`, `balance_after`, `created_at`; newest first |
| Detail | `GET /customer/transactions/{id}` | One own line, `404` otherwise |
| Digital card | `GET /customer/card` | `qr_payload` to render as a QR code for the teller |
| Statement PDF | `GET /customer/statement/export?from=&to=` | PDF download |
| Change PIN | `POST /customer/pin` `{current_pin, new_pin, new_pin_confirmation}` | Verifies current PIN with the shared lock counter; updates hash. `422` with `remaining_attempts` on a wrong current PIN |
| Withdrawal requests | `GET /customer/withdrawal-authorizations` (poll while the app is open) | Own open requests with amount, teller name, balance and balance after, deadlines |
| Approve withdrawal | `POST /customer/withdrawal-authorizations/{id}/approve` `{pin}` | See 6A. `422` with `remaining_attempts` on a wrong PIN |
| Reject withdrawal | `POST /customer/withdrawal-authorizations/{id}/reject` | `pending → rejected` |
| Logout | `POST /customer/logout` | Revokes token |

Customers cannot initiate any money movement; they can only approve or reject a
withdrawal a teller started for their account.

---

## 13. Not currently implemented

- Transfers between customers; customer-initiated transactions.
- Loans, term deposits, savings products other than account 201, fees.
- Restoring graduated accounts; closing an account with automatic pay-out.
- Partial corrections; editing or deleting transactions.
- Bulk approval; delegating approval.
- Cash movement from the vault to tellers.
- Password/PIN reset by the user; staff self-service password change; managing admin accounts.
- Major management; multi-role users; attendance integration.
- Notifications (push, email, WhatsApp); withdrawal requests are delivered by polling.
- Approval in the app for deposits or corrections (withdrawals only).
- JSON version of the annual report.
