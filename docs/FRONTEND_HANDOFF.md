# Frontend Handoff

How the React web app (staff) and the React Native + Expo app (customers)
consume the E-Teller Bank Mini Laravel API. Everything here was checked against
the backend source (routes, controllers, form requests, services, resources,
migrations, tests) at commit `5ddbd69`. If this document and the code disagree,
the code wins; please report it.

Related documents: [RBAC.md](RBAC.md), [AUTH.md](AUTH.md),
[WORKFLOWS.md](WORKFLOWS.md), [DATA_MODEL.md](DATA_MODEL.md),
[API.md](API.md) (full endpoint reference with real examples),
[UI_REQUIREMENTS.md](UI_REQUIREMENTS.md).

---

## 1. Project overview

A school "mini bank" where students (nasabah) keep savings. All money moves
through a teller at the counter: the teller records cash deposits and cash
withdrawals. Students never move money themselves; they can only view their own
account.

The backend is a JSON REST API (Laravel 13, Sanctum bearer tokens, MariaDB). It
also produces printable PDFs (receipts, daily reports, statements, accounting
reports). There is no server-rendered UI; both frontends are separate clients.

Core concepts a frontend must understand:

| Concept | Meaning |
| --- | --- |
| **Shift** (daily report) | A teller's working session for one day. Opened with an opening cash count, closed with a physical cash count that must match the system. Every transaction belongs to a shift. |
| **Transaction** | Immutable record of a deposit, withdrawal or a correction that reverses one of them. |
| **Journal** | Two accounting lines (debit/credit) written automatically per transaction. Read-only. |
| **Review** | Supervisor approves, holds or rejects a closed shift. Approval moves the cash to the vault. |
| **Correction** | The only way to undo a mistaken transaction; needs a supervisor's credentials. |

## 2. Frontend clients

| Client | Users | Backend portal |
| --- | --- | --- |
| **Staff web app** (React) | Administrator, Supervisor, Teller | Staff endpoints (`/api/staff/*`, `/api/admin/*`, `/api/teller/*`, `/api/supervisor/*`, `/api/audit/*`, `/api/customers*`, `/api/reports/*`, `/api/settings`, `/api/majors`) |
| **Customer mobile app** (React Native + Expo) | Nasabah (students) | Customer endpoints (`/api/customer/*`) |

Both use the same login endpoint, `POST /api/login`. A staff token is rejected
(`403`) on customer endpoints and vice versa.

## 3. API base URL configuration

- All endpoints are under `{APP_URL}/api`. Local default: `http://localhost:8000/api`
  (`php artisan serve`).
- **Web app**: configure the base URL with an environment variable (for example
  `VITE_API_BASE_URL`). CORS is open (`allowed_origins: *`, no credentials), so a
  different origin works for JSON. See limitation L4 about response headers;
  a Vite dev-server proxy (same origin) avoids it.
- **Expo app**: a phone cannot reach `localhost` on the developer's laptop. Run the
  backend with `php artisan serve --host=0.0.0.0 --port=8000` and use the laptop's
  LAN IP (for example `http://192.168.1.10:8000/api`); the Android emulator uses
  `http://10.0.2.2:8000/api`. Configure via `EXPO_PUBLIC_API_BASE_URL`. CORS does
  not apply to native apps.
- Send `Accept: application/json` on every request and
  `Content-Type: application/json` when sending a JSON body. (Errors under `/api/*`
  are JSON even without the header, but send it anyway.)

## 4. Authentication flow (summary)

Details in [AUTH.md](AUTH.md).

1. `POST /api/login` with `{username, password}` (staff) or `{identifier, pin}`
   (customer; identifier = NIS or account number).
2. Response `data.actor` is `"staff"` or `"customer"`; `data.token` is a Sanctum
   bearer token; `data.user` (staff) or `data.customer` holds the profile.
3. Send `Authorization: Bearer <token>` on every other request.
4. Tokens expire 8 hours after login. There is **no refresh endpoint**: on `401`,
   clear the token and show the login screen.
5. `POST /api/staff/logout` or `POST /api/customer/logout` revokes the current token.
6. Current user: `GET /api/staff/me` (staff) or `GET /api/customer/me` (customer).

## 5. Roles

| Role | Where stored | Client | Summary |
| --- | --- | --- | --- |
| `admin` | `users.role` | Web | Manages staff, customer accounts, settings; reads audit data and reports. Never handles money. |
| `supervisor` | `users.role` | Web | Reviews tellers' daily reports, sees the vault ledger, reads staff, accounts, audit data and reports. Never handles money. |
| `teller` | `users.role` | Web | Runs a shift, looks up customers, records deposits/withdrawals/corrections, closes the shift, prints receipts and daily reports. |
| Nasabah (customer) | `customers` table (separate model, not a role value) | Mobile | Views own balance, statement and card; changes own PIN. |

Full permission matrix and rules: [RBAC.md](RBAC.md). The frontend should route by
`actor` and then by `user.role`, but **hiding pages is not security**; the backend
re-checks every request.

## 6. Endpoint-to-feature map

| Feature | Endpoints | Roles |
| --- | --- | --- |
| Login / logout / profile | `POST /login`; `GET /staff/me`, `POST /staff/logout`; `GET /customer/me`, `POST /customer/logout` | all |
| Reference data | `GET /majors`, `GET /settings` | any staff |
| Staff list / detail | `GET /staff`, `GET /staff/{id}` | admin, supervisor |
| Staff create / edit / block / unblock / delete / import | `POST /admin/staff`, `PATCH /admin/staff/{id}`, `POST /admin/staff/{id}/block`, `.../unblock`, `DELETE /admin/staff/{id}`, `POST /admin/staff/import` | admin |
| Settings edit | `PATCH /admin/settings` | admin |
| Customer list / detail | `GET /customers`, `GET /customers/{id}` | admin, supervisor |
| Customer open / edit / PIN reset / suspend / graduate / card | `POST /admin/customers`, `PATCH /admin/customers/{id}`, `POST /admin/customers/{id}/deactivate`, `GET /admin/customers/{id}/card` | admin |
| Customer statement PDF | `GET /customers/{id}/statement` | admin, supervisor |
| Shift | `GET /teller/shift`, `POST /teller/shift/open`, `POST /teller/shift/close` | teller |
| Shift history / print | `GET /teller/daily-reports`, `GET /teller/daily-reports/{id}`, `GET /teller/daily-reports/{id}/export` | teller (own) |
| Customer lookup (QR or typed) | `GET /teller/customers/lookup?identifier=` | teller |
| Deposit / withdrawal | `POST /teller/deposits`, `POST /teller/withdrawals` | teller |
| Today's transactions / receipt | `GET /teller/transactions?date=`, `GET /teller/transactions/{id}/receipt` | teller (own) |
| Correction | `POST /teller/transactions/{id}/correct` | teller (own) + supervisor credentials |
| Report review | `GET /supervisor/daily-reports`, `GET /supervisor/daily-reports/{id}`, `POST .../approve`, `.../hold`, `.../reject`, `GET .../export` | supervisor |
| Vault ledger | `GET /supervisor/cash-transfers`, `GET /supervisor/cash-transfers/{id}` | supervisor |
| Audit log / journal / trial balance / exports | `GET /audit/transactions`, `GET /audit/transactions/{id}`, `GET /audit/transactions/export`, `GET /audit/journals`, `GET /audit/journals/summary`, `GET /audit/journals/export` | admin, supervisor |
| Annual report PDF | `GET /reports/annual?school_year=` | admin, supervisor |
| Mobile: balance, history, card, PIN, statement PDF | `GET /customer/balance`, `GET /customer/transactions`, `GET /customer/transactions/{id}`, `GET /customer/card`, `POST /customer/pin`, `GET /customer/statement/export` | customer |

All 58 API routes are listed in [RBAC.md](RBAC.md#7-endpoint-restrictions).

## 7. Request requirements

| Rule | Detail |
| --- | --- |
| Auth header | `Authorization: Bearer <token>` on everything except `POST /login` (and the aliases `/staff/login`, `/customer/login`) |
| Body format | JSON, except `POST /admin/staff/import` (`multipart/form-data`, field `file`) |
| Methods | `PATCH` and `PUT` both work for updates (partial updates; send only changed fields) |
| Path ids | Numeric database ids (`/customers/12`), except `account_number` inside bodies |
| Money | Integer Rupiah. No decimals, no thousand separators, no strings like `"Rp50.000"` |
| Idempotency | Optional header `Idempotency-Key` on `POST /teller/deposits`, `/teller/withdrawals`, `/teller/transactions/{id}/correct` (letters, digits, `_ . : -`, max 100). Strongly recommended, see §11 |
| Cash counts | `{"denominations": {"100000": 2, "50000": 1}}` with keys from 100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100; quantities 0–100000 |

## 8. Response structures

| Kind | Shape |
| --- | --- |
| Single object | `{ "data": { ... } }` |
| Paginated list | `{ "data": [ ... ], "links": {first, last, prev, next}, "meta": {current_page, from, last_page, links, path, per_page, to, total} }` |
| List with extras | Some lists add top-level keys: vault ledger `total_amount`; shift/report detail `transactions`; `GET /teller/shift` adds `can_transact` (and `closed_today` when there is no shift) |
| Message only | `{ "message": "Berhasil keluar." }` (logout, PIN change, staff delete) |
| PDF | Binary with `Content-Type: application/pdf` and `Content-Disposition: attachment; filename=...` |
| Status codes | `200` read/update, `201` created (new transaction, new staff, new customer, shift open, import), `200` + replay for a repeated `Idempotency-Key` |

Enums are returned as their string values (`"deposit"`, `"on_hold"`, `"active"`).
Optional relations are omitted entirely when not loaded (for example a
`DailyReport` has `teller` only on supervisor endpoints). Object fields are in
[DATA_MODEL.md](DATA_MODEL.md); full examples in [API.md](API.md).

## 9. Validation rules (frontend-relevant)

Server validation is authoritative; mirror these for instant feedback.

| Field | Rule |
| --- | --- |
| Staff `username` | required, `alpha_dash` (letters, digits, `-`, `_`), max 50, unique |
| Staff `password` | min 8 (on create; optional on update) |
| Staff `role` (create/update) | `teller` or `supervisor` only |
| Staff `class` / `phone` | optional; class max 20; phone digits, spaces, `+`, `-`, max 20 |
| Customer `nis` | required, max 20, unique (including closed accounts) |
| Customer `class` / `group` | `X`/`XI`/`XII` / `1`–`4` |
| Customer `major_id` | existing id from `GET /majors` |
| Customer `pin` | exactly 6 digits (string) |
| Customer `account_status` (update) | `active` or `suspended` only |
| Login `identifier` / `pin` | required, max 50 / exactly 6 digits |
| Transaction `account_number` | existing, not closed |
| Transaction `amount` | integer 1 – 100,000,000 (**see L1: use multiples of 100**) |
| Withdrawal `pin` | exactly 6 digits |
| Correction | `reason` max 255; `supervisor_username`, `supervisor_password` required and must belong to an active supervisor |
| Review `note` | max 1000; **required** for hold and reject, optional for approve |
| Customer PIN change | `current_pin`, `new_pin` (6 digits, different from current), `new_pin_confirmation` |
| Date filters | `from`/`to` any parseable date, `to` ≥ `from`; audit endpoints also accept `YYYY-MM-DD HH:MM` |
| Import file | `.xlsx` or `.csv`, max 2 MB, max 500 rows |
| `per_page` | clamped to 1–100 (never an error) |

## 10. Error behavior

Every error body has `message`. Handle by status:

| Status | Meaning | UI expectation |
| --- | --- | --- |
| `401` | No/invalid/expired token | Clear stored token, go to login |
| `403` | Wrong actor, wrong role, blocked staff, closed customer account, or editing an admin account | Log out and return to login (UKK rubric 3.2), except the "admin account cannot be changed" case on staff edit, which is a normal error message |
| `404` | Not found or not yours (`Data tidak ditemukan.`, `Nasabah tidak ditemukan.`) | "Not found" state |
| `405` | Wrong method | Programming error |
| `409` | Staff delete refused (has history) | Offer "Block" instead |
| `422` + `errors` | Field validation (`errors.field[]`) | Show messages next to fields |
| `422` without `errors` | Business rule refused (`message` only, Indonesian) | Show the message prominently; nothing was saved |
| `429` | Rate limit (login 5/min per identifier+IP; corrections 10/min per teller) | "Too many attempts, wait a minute" |
| `500` | Server error. All money operations are rolled back | Generic error. In local dev (`APP_DEBUG=true`) the body includes a stack trace: never show it raw |

Business `422` responses with extra fields:

| Case | Extra fields |
| --- | --- |
| Cash mismatch on shift close | `system_balance`, `physical_balance`, `difference` |
| Wrong/locked withdrawal PIN or PIN change | `remaining_attempts`, `pin_locked_until` (UTC ISO or null) |

Language: business messages are Indonesian; Laravel field-validation messages are
English (`"The amount field must be at least 1."`). Consider mapping field errors to
your own Indonesian texts.

## 11. Loading-sensitive operations

| Operation | Why it is sensitive | Frontend handling |
| --- | --- | --- |
| Deposit / withdrawal / correction | Moves money; a retry after a timeout could record twice | Generate an `Idempotency-Key` (e.g. UUID) when the confirmation dialog opens; reuse it for retries of that same submission; disable the submit button while pending. `201` = recorded, `200` = already recorded earlier (replay) |
| Withdrawal | Wrong PIN attempts are counted even if the request fails for another reason (L2) | Check the shift and the customer's status, balance and `pin_locked_until` **before** asking for the PIN |
| Shift close | Saves nothing on mismatch; on success the shift locks | Show the system balance and the counted total before submitting; on `422` show the difference |
| Supervisor approve | Final; creates the vault transfer | Confirmation dialog |
| Customer deactivate (graduate) | Revokes tokens, hides the account; no undo endpoint (L5) | Strong confirmation; warn if balance > 0 |
| Staff block / delete | Block revokes tokens immediately | Confirmation |
| PDF downloads | Response is binary on success, JSON on error | Request as blob; if `Content-Type` is JSON, parse and show the message |
| Login | Rate limited 5/min | Disable button while pending |

## 12. Transaction behavior

- **Atomic.** Balance update, transaction row and both journal lines are saved in
  one database transaction with row locks. Any failure saves nothing.
- **Immutable.** No endpoint edits or deletes a transaction. Mistakes are reversed by
  a correction (a new transaction).
- **Inside a shift.** Deposits and withdrawals need the teller's shift to be active
  (`open` or `rejected`) **and dated today**. Corrections need the original's shift to
  still be active (any date).
- **Balance chain.** Each transaction stores `balance_before` and `balance_after`.
- **Journal.** Deposit and withdrawal-correction: Debit 101 Kas / Credit 201 Tabungan.
  Withdrawal and deposit-correction: the reverse.
- **Statuses.** Transactions have **no status field**. "Corrected" means another
  transaction points to it (`corrected_by`); a correction points back
  (`corrects_transaction_id`).

## 13. Important business rules

| Rule | Where enforced |
| --- | --- |
| Withdrawals must leave at least the minimum balance (setting `min_balance`, default Rp10.000) | Service |
| Withdrawals require the customer's 6-digit PIN; 3 wrong in a row lock withdrawals for 15 minutes; a correct PIN resets the counter; an admin PIN reset lifts the lock | Service (config `bank.pin_*`) |
| Only `active` accounts can deposit/withdraw; `suspended` accounts can log in and be corrected but not transact; closed (graduated) accounts cannot be found or transacted | Service / validation |
| One shift per teller per day; an old active shift must be closed before opening a new one | Service + unique DB key |
| Closing requires physical cash = opening + net deposits − net withdrawals | Service |
| Shift totals are net of corrections | Service |
| Closing locks deposits/withdrawals for that day until a supervisor rejects the report | Service |
| Daily report PDF only after a successful close (`submitted`, `on_hold`, `approved`) | Service |
| Approve allowed from `submitted`/`on_hold`; hold only from `submitted`; reject from `submitted`/`on_hold` and only if the teller has no other active shift | Service |
| Approval creates the drawer→vault transfer (if counted cash > 0); approved reports are final | Service + model guard |
| A transaction can be corrected once, by its own teller, within the original's active shift, with an active supervisor's credentials; a deposit correction cannot make the balance negative (but may go below the minimum) | Service + DB unique/CHECK |
| Admins can create/edit only tellers and supervisors; admin accounts cannot be edited, blocked or deleted through the API | Validation + controller |
| Staff with history cannot be deleted (only blocked) | DB `ON DELETE RESTRICT` |
| New accounts get a generated number, status `active`, balance 0 | Service |

## 14. Account identification and QR

- **Account number format**: `{MAJOR CODE}-{SCHOOL YEAR}-{SERIAL}`, e.g.
  `RPL-2026-001`. School year = the July–June year in which the account was opened.
  Generated by the server; never editable.
- **QR payload**: the string `BANKMINI:{account_number}`, e.g. `BANKMINI:RPL-2026-001`.
  Provided by `GET /admin/customers/{id}/card` and `GET /customer/card`
  (`qr_payload`). **The backend does not generate QR images**; the frontend renders
  `qr_payload` as a QR code (card printing in the web app, digital card in the mobile app).
- **Lookup**: `GET /teller/customers/lookup?identifier=` accepts the scanned payload,
  a typed account number, or a typed NIS. Account number is tried first, then NIS.
  Closed accounts return `404`. URL-encode the value (the payload contains `:`).
- **Scanner mode**: `GET /settings` returns `qr_scanner_mode` (`webcam`, `usb`,
  `disabled`). The backend only stores it; it does not change lookup behavior. The
  teller UI should read it: `webcam` = camera scanner, `usb` = a keyboard-wedge
  scanner typing into the lookup field, `disabled` = manual entry only.

## 15. Key workflows (UI contract)

Full backend detail in [WORKFLOWS.md](WORKFLOWS.md).

### 15.1 Login

```text
USER ACTION   Enters username+password (web) or NIS/account number+PIN (mobile)
→ ENDPOINT    POST /api/login
→ REQUEST     {"username","password"} or {"identifier","pin"}
→ VALIDATION  required fields; PIN 6 digits; credentials; staff not blocked; account not closed; 5/min limit
→ RESPONSE    200 {data:{actor, token, token_type, user|customer}} · 422 {message, errors} · 429
→ UI          Store token; route by actor (and user.role); show field error on 422
```

The web app should refuse `actor: "customer"` (and the mobile app `actor: "staff"`)
and tell the user to use the other app.

### 15.2 Teller: start of shift

```text
USER ACTION   Opens the teller page
→ ENDPOINT    GET /api/teller/shift
→ RESPONSE    {data: DailyReport|null, can_transact, transactions[] | closed_today}
→ UI          data=null & closed_today=false → show "Open shift" form
              data=null & closed_today=true  → "Shift closed for today", transactions disabled
              data.status=open & can_transact → counter screen
              data present & can_transact=false → old shift must be closed first
              data.status=rejected → show supervisor_note; allow corrections and re-close

USER ACTION   Counts the drawer per denomination, submits
→ ENDPOINT    POST /api/teller/shift/open
→ REQUEST     {"denominations":{"50000":2,"10000":5}}
→ VALIDATION  allowed denominations, quantities 0–100000; no active shift; no shift today yet
→ RESPONSE    201 DailyReport (status open, opening_balance, opening_counts) · 422 message
→ UI          Go to the counter screen
```

### 15.3 Account lookup

```text
USER ACTION   Scans the card QR or types account number/NIS
→ ENDPOINT    GET /api/teller/customers/lookup?identifier=BANKMINI%3ARPL-2026-001
→ VALIDATION  identifier required, max 60
→ RESPONSE    200 {data:{id, account_number, nis, full_name, class, group, major_name, account_status, balance, pin_locked_until}} · 404
→ UI          Show a customer card; disable deposit/withdraw when account_status ≠ active;
              disable withdraw while pin_locked_until is in the future; show balance
```

### 15.4 Deposit

```text
USER ACTION   Enters amount, confirms
→ ENDPOINT    POST /api/teller/deposits  (header Idempotency-Key)
→ REQUEST     {"account_number":"RPL-2026-001","amount":125000}
→ VALIDATION  account exists & not closed; amount 1–100,000,000; active shift dated today; account active
→ RESPONSE    201 Transaction (balance_before/after, amount_in_words) · 200 replay · 422 message/errors
→ UI          Success screen with new balance; offer receipt (GET /teller/transactions/{id}/receipt);
              refresh shift totals; on 422 show message, nothing was saved
```

Confirmation dialog should show account number, name, amount and amount in words
(compute locally or use `amount_in_words` after saving).

### 15.5 Withdrawal

```text
USER ACTION   Enters amount; customer types PIN on the confirmation screen
→ ENDPOINT    POST /api/teller/withdrawals  (header Idempotency-Key)
→ REQUEST     {"account_number":"RPL-2026-001","amount":30000,"pin":"123456"}
→ VALIDATION  as deposit + pin 6 digits; PIN correct & not locked (checked first); shift; status;
              balance − amount ≥ min_balance
→ RESPONSE    201 Transaction · 200 replay ·
              422 {"message":"PIN salah. Sisa percobaan: 2.","remaining_attempts":2,"pin_locked_until":null} ·
              422 locked (remaining_attempts 0, pin_locked_until) ·
              422 "Saldo tidak mencukupi ... penarikan maksimal RpX."
→ UI          Pre-check balance − amount ≥ min_balance (from GET /settings) before asking for the PIN;
              mask the PIN input; clear it after every attempt; show remaining attempts / lock time
```

### 15.6 Correction

```text
USER ACTION   Selects one of today's transactions → "Koreksi"; enters reason; supervisor types username+password
→ ENDPOINT    POST /api/teller/transactions/{id}/correct  (header Idempotency-Key)
→ REQUEST     {"reason":"...","supervisor_username":"...","supervisor_password":"..."}
→ VALIDATION  reason; active supervisor credentials; own transaction (else 404); original's shift active;
              not already corrected; not a correction; deposit correction cannot make balance negative
→ RESPONSE    201 Transaction (type *_correction, corrects_transaction_id, authorized_by) · 422 · 429
→ UI          Mark the original as corrected (corrected_by); refresh shift; then record the right amount
```

Offer "Koreksi" only for rows where `type` is `deposit`/`withdrawal`, `corrected_by`
is null, and `daily_report_id` equals the active shift id.

### 15.7 Teller: close shift

```text
USER ACTION   Counts the drawer per denomination, submits
→ ENDPOINT    POST /api/teller/shift/close
→ REQUEST     {"denominations":{"100000":2,"50000":1,"2000":1}}
→ VALIDATION  active shift exists; physical total = system_balance
→ RESPONSE    200 DailyReport (status submitted, difference 0) ·
              422 {"message":"Uang fisik kurang Rp2.000 ...","system_balance":252000,"physical_balance":250000,"difference":-2000}
→ UI          On 422 keep the form, show the difference, suggest recount/correction;
              on 200 offer the PDF (GET /teller/daily-reports/{id}/export)
```

### 15.8 Supervisor review

```text
USER ACTION   Opens pending reports, picks one, decides
→ ENDPOINTS   GET /api/supervisor/daily-reports?status=submitted → GET /api/supervisor/daily-reports/{id}
              → POST .../approve {note?} | .../hold {note} | .../reject {note}
→ VALIDATION  note rules; current status allows the decision; reject only if teller has no other active shift
→ RESPONSE    200 DailyReport (approve adds cash_transfer) · 422 message/errors
→ UI          One report at a time (no bulk); show counts, transactions and difference; confirm approve
```

### 15.9 Mobile: account overview

```text
USER ACTION   Opens the app after login
→ ENDPOINTS   GET /api/customer/balance · GET /api/customer/transactions?per_page=5 · GET /api/customer/card
→ RESPONSE    balance {account_number, full_name, account_status, balance, min_balance, as_of};
              statement lines {id, type, direction, description, amount, balance_after, created_at}
→ UI          Balance card; "suspended" notice when account_status = suspended; latest lines;
              infinite scroll on the history screen (page/per_page); pull to refresh;
              digital card shows qr_payload as a QR code for the teller to scan
```

## 16. Pagination, filtering, sorting

- Paginated endpoints: `GET /staff`, `/customers`, `/teller/transactions`,
  `/teller/daily-reports`, `/supervisor/daily-reports`, `/supervisor/cash-transfers`,
  `/audit/transactions`, `/audit/journals`, `/customer/transactions`.
- Not paginated: `GET /majors`, shift/report `transactions` arrays.
- `?page=` (1-based) and `?per_page=` (default 15; teller transactions 50; journals
  30; customer transactions 20; clamped to 1–100).
- **No sorting parameters exist.** Fixed orders: staff and customers by name;
  transactions, journals, transfers newest first (by id); daily reports by
  `report_date` desc; shift/report transaction arrays oldest first.
- Filters per endpoint are listed in [API.md](API.md). Filter values on `/customers`
  (`status`, `class`, `group`, `major_id`) and `/staff` (`role`, `status`) are **not
  validated**: an unknown value returns an empty list (or the default for `status`)
  instead of `422`. Use fixed dropdown values.
- Text searches (`search`, `teller`, `customer`) are "contains" matches.

## 17. Date, time and currency

- **Timestamps** (`created_at`, `opened_at`, `transferred_at`, `as_of`,
  `pin_locked_until`, ...) are ISO 8601 in **UTC** (`...Z`). Display in
  Asia/Jakarta (WIB, UTC+7).
- **Dates** (`report_date`, filter `date`/`from`/`to`) are `YYYY-MM-DD` in the server
  timezone (Asia/Jakarta). "Today" for shifts is the server's date.
- The PIN-lock message text contains a local time (`pukul 20:25`, WIB) while the
  field `pin_locked_until` is UTC.
- **Money** is integer Rupiah in every request and response. Format for display as
  `Rp1.500.000` (dot thousands separator). `amount_in_words` gives Indonesian
  words for transactions.

## 18. Known backend limitations

These are current behaviors. They are documented, not fixed.

| # | Limitation | Frontend impact |
| --- | --- | --- |
| L1 | Amounts are not required to be multiples of 100, but cash can only be counted in denominations ≥ Rp100. Any amount like Rp150 makes the shift impossible to close until that transaction is corrected (verified). | Enforce multiples of 100 in the teller UI |
| L2 | Withdrawal checks the PIN **before** the shift, account status and balance. A wrong PIN is counted even when the withdrawal would have failed anyway. | Pre-check shift (`can_transact`), status, balance and lock before asking for the PIN |
| L3 | After a rejection, the reopened shift still shows the previous `physical_balance`, `difference` and `closing_counts` until it is closed again (verified). | For `status = rejected`, ignore those fields or label them "previous count" |
| L4 | CORS exposes no response headers, so a browser app on another origin cannot read `Idempotent-Replayed` or `Content-Disposition`. | Use status `201` vs `200` to detect replays; name downloaded PDFs yourself; or serve the web app same-origin / via a dev proxy |
| L5 | Graduating (deactivating) an account does not require a zero balance, cannot be undone via the API, and closed accounts cannot be transacted. With a minimum balance > 0 the last Rp10.000 can never be withdrawn. | Warn before deactivation when balance > 0 |
| L6 | No self-service password change for staff. Admin accounts cannot be edited at all through the API (including password). | No "change my password" page for staff |
| L7 | No token refresh; tokens expire 8 hours after login (absolute). | Re-login on `401` |
| L8 | No "forgot password/PIN". Customer PIN can be reset by an admin; staff passwords by an admin (non-admin accounts only). | Show "contact the administrator" |
| L9 | Mixed languages: business messages Indonesian, field-validation messages English. | Map field errors if a single language is needed |
| L10 | Annual report, journal report and audit export exist only as PDF (no JSON equivalent for the annual summary). | Show PDFs; build on-screen tables from `/audit/*` JSON |
| L11 | Filter values on `/customers` and `/staff` are not validated. | Use dropdowns |
| L12 | `qr_scanner_mode` is informational only; lookup accepts QR payloads even when the mode is `disabled`. | The UI decides what to offer |
| L13 | Majors cannot be created or edited through the API (seeded). | Read-only dropdown |
| L14 | The teller's read access to accounts is a single-customer lookup; there is no account list for tellers. | No account browsing for tellers |
| L15 | `customers.balance` is a 32-bit integer (max Rp2.147.483.647). | Not a practical concern for a school |
| L16 | Idempotency keys never expire; reusing an old key for a new transaction returns `422`. | Always generate a fresh key per new transaction |
| L17 | The QR payload is the plain account number (not signed); it identifies but does not authorize. Withdrawals still need the PIN. | Always ask for the PIN on withdrawals |
| L18 | No sort parameters on any list; orders are fixed per endpoint (see §16). | Sort client-side within a page only, or accept the server order |

## 19. Known inconsistencies or unclear behavior

| # | Item | Status |
| --- | --- | --- |
| I1 | The UKK soal's RBAC matrix gives Admin no access to "input transaksi" and read access to the journal. The backend also lets Admin read the transaction log (`/audit/transactions*`). Reading is not input, but a strict reading of the matrix could disagree. | Implemented as described; decide whether the admin UI shows the log |
| I2 | `POST /staff/login` and `POST /customer/login` duplicate `POST /login`. | Use `/login` only |
| I3 | The customer login message `Rekening ini sudah tidak aktif.` can practically never appear: `inactive` is only set by deactivation, which also soft-deletes, and soft-deleted accounts get the generic "wrong NIS/PIN" message. | Treat both as login failure |
| I4 | `docs/API.md` described `physical_balance`/`difference` as null while a shift is open, which is wrong for rejected shifts (L3). | Corrected in API.md |
| I5 | `docs/API.md`'s access matrix showed Admin with no access in the deposit/withdrawal row although Admin can read the audit log. | Corrected in API.md |
| I6 | The UKK interview asked for Customer Service / Accounting roles and student vs employee savings products; the backend implements the soal's four roles and one savings product (account 201). | Not implemented; do not build UI for them |

## 20. Not implemented (do not build UI for these)

Customer self-registration · customer-initiated transactions or transfers between
accounts · loans, term deposits, fees · notifications/push · password or PIN
"forgot" flows · staff self-service profile/password · restoring graduated accounts ·
major management · multi-role users · attendance (absensi) integration · bulk
approval of reports · editing or deleting transactions · JSON annual report ·
server-generated QR images.
