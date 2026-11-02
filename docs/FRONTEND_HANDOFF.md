# Frontend Handoff

How the React web app (staff) and the React Native + Expo app (customers)
consume the E-Teller Bank Mini Laravel API. Everything here was checked against
the backend source (routes, controllers, form requests, services, resources,
migrations, tests) at commit `5ddbd69`, and updated for the mobile withdrawal
approval and for the Firebase customer login and activation
([CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)). If this document and the code disagree,
the code wins; please report it.

Related documents: [RBAC.md](RBAC.md), [AUTH.md](AUTH.md),
[WORKFLOWS.md](WORKFLOWS.md), [DATA_MODEL.md](DATA_MODEL.md),
[API.md](API.md) (full endpoint reference with real examples),
[UI_REQUIREMENTS.md](UI_REQUIREMENTS.md), [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)
(customer login, activation, Firebase and SMTP setup).

---

## 1. Project overview

A school "mini bank" where students (nasabah) keep savings. All money moves
through a teller at the counter: the teller records cash deposits and cash
withdrawals. Students never move money themselves; they can view their own
account and **approve (with their PIN) or reject a withdrawal the teller started** for
them (see §15.5 and [PIN_VERIFICATION.md](PIN_VERIFICATION.md)).

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
| **Activation** | An administrator creates the account with the student's email; the student activates the app from the emailed link with a Google or email/password Firebase account. Until then the account is `pending` for the app but works at the counter. |

## 2. Frontend clients

| Client | Users | Backend portal |
| --- | --- | --- |
| **Staff web app** (React) | Administrator, Supervisor, Teller | Staff endpoints (`/api/staff/*`, `/api/admin/*`, `/api/teller/*`, `/api/supervisor/*`, `/api/audit/*`, `/api/customers*`, `/api/reports/*`, `/api/settings`, `/api/majors`) |
| **Customer mobile app** (React Native + Expo) | Nasabah (students) | Customer endpoints (`/api/customer/*`) |

Both can use the login gateway `POST /api/login`: staff with username + password,
customers with a Firebase ID token (customers sign in with Google or email/password
through the Firebase client SDK, after a first-time activation; see §15.10 and
[CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)). A staff token is rejected (`403`) on customer
endpoints and vice versa.

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

Details in [AUTH.md](AUTH.md) and, for customers, [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md).

1. **Staff:** `POST /api/login` with `{username, password}`.
   **Customers:** sign in with Firebase (Google or email/password), get the Firebase ID
   token, then `POST /api/customer/auth/login` (or `/api/login`) with `{id_token}`. The
   first time, the customer activates from the emailed link instead (§15.10).
2. Response `data.actor` is `"staff"` or `"customer"`; `data.token` is a Sanctum
   bearer token; `data.user` (staff) or `data.customer` holds the profile; customer
   responses add `data.expires_at`.
3. Send `Authorization: Bearer <token>` on every other request.
4. Staff tokens expire 8 hours after login; customer tokens after
   `CUSTOMER_SESSION_MINUTES` (default 30 days). There is **no refresh endpoint**: on
   `401`, clear the token and show the login screen.
5. Inside a customer session the app unlocks with the PIN (`POST /api/customer/unlock`);
   the PIN also approves withdrawals.
6. `POST /api/staff/logout` or `POST /api/customer/logout` revokes the current token
   (`POST /api/customer/logout-all` revokes all of a customer's sessions). The customer
   app also signs out of Firebase.
7. Current user: `GET /api/staff/me` (staff) or `GET /api/customer/me` (customer).
8. `POST /api/customer/login` (NIS/account number + PIN) **never returns a token**; it
   answers `403 activation_required` or `403 firebase_login_required` after a correct PIN.

## 5. Roles

| Role | Where stored | Client | Summary |
| --- | --- | --- | --- |
| `admin` | `users.role` | Web | Manages staff, customer accounts, settings; reads audit data and reports. Never handles money. |
| `supervisor` | `users.role` | Web | Reviews tellers' daily reports, sees the vault ledger, reads staff, accounts, audit data and reports. Never handles money. |
| `teller` | `users.role` | Web | Runs a shift, looks up customers, records deposits/withdrawals/corrections, closes the shift, prints receipts and daily reports. |
| Nasabah (customer) | `customers` table (separate model, not a role value) | Mobile | Views own balance, statement and card; changes own PIN; approves or rejects withdrawals the teller started. |

Full permission matrix and rules: [RBAC.md](RBAC.md). The frontend should route by
`actor` and then by `user.role`, but **hiding pages is not security**; the backend
re-checks every request.

## 6. Endpoint-to-feature map

| Feature | Endpoints | Roles |
| --- | --- | --- |
| Login / logout / profile | `POST /login`; `GET /staff/me`, `POST /staff/logout`; `POST /customer/auth/login`, `GET /customer/me`, `POST /customer/logout`, `POST /customer/logout-all` | all |
| Customer activation (public) | `POST /customer/activation/inspect`, `POST /customer/activation/complete`, `POST /customer/activation/resend`; NIS + PIN check `POST /customer/login` | customer (no token yet) |
| Customer app unlock | `POST /customer/unlock` `{pin}` | customer |
| Reference data | `GET /majors`, `GET /settings` | any staff |
| Staff list / detail | `GET /staff`, `GET /staff/{id}` | admin, supervisor |
| Staff create / edit / block / unblock / delete / import | `POST /admin/staff`, `PATCH /admin/staff/{id}`, `POST /admin/staff/{id}/block`, `.../unblock`, `DELETE /admin/staff/{id}`, `POST /admin/staff/import` | admin |
| Settings edit | `PATCH /admin/settings` | admin |
| Customer list / detail | `GET /customers`, `GET /customers/{id}` | admin, supervisor |
| Customer open / edit / PIN reset / suspend / graduate / card / invitation | `POST /admin/customers`, `PATCH /admin/customers/{id}`, `POST /admin/customers/{id}/deactivate`, `GET /admin/customers/{id}/card`, `POST /admin/customers/{id}/invitation` | admin |
| Customer statement PDF | `GET /customers/{id}/statement` | admin, supervisor |
| Shift | `GET /teller/shift`, `POST /teller/shift/open`, `POST /teller/shift/close` | teller |
| Shift history / print | `GET /teller/daily-reports`, `GET /teller/daily-reports/{id}`, `GET /teller/daily-reports/{id}/export` | teller (own) |
| Customer lookup (QR or typed) | `GET /teller/customers/lookup?identifier=` | teller |
| Deposit / withdrawal (PIN at terminal, if enabled) | `POST /teller/deposits`, `POST /teller/withdrawals` | teller |
| Withdrawal with mobile approval (default) | `POST /teller/withdrawal-authorizations`, `GET /teller/withdrawal-authorizations[/{id}]`, `POST .../{id}/cancel`, `POST .../{id}/complete` | teller |
| Today's transactions / receipt | `GET /teller/transactions?date=`, `GET /teller/transactions/{id}/receipt` | teller (own) |
| Correction | `POST /teller/transactions/{id}/correct` | teller (own) + supervisor credentials |
| Report review | `GET /supervisor/daily-reports`, `GET /supervisor/daily-reports/{id}`, `POST .../approve`, `.../hold`, `.../reject`, `GET .../export` | supervisor |
| Vault ledger | `GET /supervisor/cash-transfers`, `GET /supervisor/cash-transfers/{id}` | supervisor |
| Audit log / journal / trial balance / exports | `GET /audit/transactions`, `GET /audit/transactions/{id}`, `GET /audit/transactions/export`, `GET /audit/journals`, `GET /audit/journals/summary`, `GET /audit/journals/export` | admin, supervisor |
| Annual report PDF | `GET /reports/annual?school_year=` | admin, supervisor |
| Mobile: balance, history, card, PIN, statement PDF | `GET /customer/balance`, `GET /customer/transactions`, `GET /customer/transactions/{id}`, `GET /customer/card`, `POST /customer/pin`, `GET /customer/statement/export` | customer |
| Mobile: approve/reject a withdrawal | `GET /customer/withdrawal-authorizations[/{id}]`, `POST .../{id}/approve` `{pin}`, `POST .../{id}/reject` | customer |

All 74 API routes are listed in [RBAC.md](RBAC.md#7-endpoint-restrictions).

## 7. Request requirements

| Rule | Detail |
| --- | --- |
| Auth header | `Authorization: Bearer <token>` on everything except `POST /login` (and the aliases `/staff/login`, `/customer/auth/login`, `/customer/login`) and the three `/customer/activation/*` endpoints |
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
| Customer `email` | required on create; valid email, max 255, unique (case-insensitive, stored lowercase); editable only while `activation_status` is `pending` |
| Customer `class` / `group` | `X`/`XI`/`XII` / `1`–`4` |
| Customer `major_id` | existing id from `GET /majors` |
| Customer `pin` | exactly 6 digits (string) |
| Customer `account_status` (update) | `active` or `suspended` only |
| Login `identifier` / `pin` | required, max 50 / exactly 6 digits |
| Firebase `id_token` | required string, max 4096 |
| Activation `token` | exactly 64 characters (from the link's `?token=`) |
| Unlock `pin` | exactly 6 digits |
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
| `401`/`403`/`409`/`410`/`503` with `code` | Customer login, activation and invitation refusals (`firebase_token_invalid`, `activation_required`, `email_mismatch`, …) | Switch on `code`; full table in §15.10 |
| `403` | Wrong actor, wrong role, blocked staff, closed customer account, or editing an admin account | Log out and return to login (UKK rubric 3.2), except the "admin account cannot be changed" case on staff edit, which is a normal error message |
| `404` | Not found or not yours (`Data tidak ditemukan.`, `Nasabah tidak ditemukan.`) | "Not found" state |
| `405` | Wrong method | Programming error |
| `409` | Staff delete refused (has history) | Offer "Block" instead |
| `422` + `errors` | Field validation (`errors.field[]`) | Show messages next to fields |
| `422` without `errors` | Business rule refused (`message` only, Indonesian) | Show the message prominently; nothing was saved |
| `422` with `code: customer_app_not_activated` | Withdrawal approval requested for a customer who has not activated the app (§15.5) | Show `message`; in `both` mode switch to the PIN pad |
| `429` | Rate limit (login 5/min per identifier+IP, Firebase login 30/min per IP; activation 20/min per IP; corrections 10/min per teller; see CUSTOMER_AUTH.md §3.7) | "Too many attempts, wait a minute" |
| `500` | Server error. All money operations are rolled back | Generic error. In local dev (`APP_DEBUG=true`) the body includes a stack trace: never show it raw |

Business `422` responses with extra fields:

| Case | Extra fields |
| --- | --- |
| Cash mismatch on shift close | `system_balance`, `physical_balance`, `difference` |
| Wrong/locked PIN on approval, terminal withdrawal, PIN change or app unlock | `remaining_attempts`, `pin_locked_until` (UTC ISO or null) |
| Withdrawal request not usable in its current state | `status` (e.g. `expired`, `cancelled`, `consumed`) |

Language: business messages are Indonesian; Laravel field-validation messages are
English (`"The amount field must be at least 1."`). Consider mapping field errors to
your own Indonesian texts.

## 11. Loading-sensitive operations

| Operation | Why it is sensitive | Frontend handling |
| --- | --- | --- |
| Deposit / withdrawal / correction | Moves money; a retry after a timeout could record twice | Generate an `Idempotency-Key` (e.g. UUID) when the confirmation dialog opens; reuse it for retries of that same submission; disable the submit button while pending. `201` = recorded, `200` = already recorded earlier (replay) |
| Withdrawal (terminal mode) | Wrong PIN attempts are counted even if the request fails for another reason (L2) | Check the shift and the customer's status, balance and `pin_locked_until` **before** asking for the PIN |
| Withdrawal request: complete | Moves money | Safe to retry: the same `complete` call returns the same transaction (`200`). Disable the button while pending |
| Withdrawal request: approve (mobile) | Uses a PIN attempt | Disable the button while pending; clear the PIN after each attempt |
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
| Withdrawals require the customer's 6-digit PIN; 3 wrong in a row (anywhere the PIN is entered, including the app unlock) lock it for 15 minutes; a correct PIN resets the counter; an admin PIN reset lifts the lock | Service (config `bank.pin_*`) |
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
| New accounts get a generated number, status `active`, balance 0, `activation_status: pending`, and an invitation email | Service |
| A customer session starts only from a verified Firebase ID token (login or activation); the customer is found by Firebase UID, never by email; NIS + PIN alone gives no token | Service |
| An account can be activated once; the Firebase email must equal the registered email and be verified; one Firebase account per customer | Service + unique DB keys + CHECK |

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
STAFF (web)
USER ACTION   Enters username + password
→ ENDPOINT    POST /api/login {"username","password"}
→ RESPONSE    200 {data:{actor:"staff", token, token_type, user}} · 422 {message, errors} · 429
→ UI          Store token; route by user.role; show field error on 422

CUSTOMER (app)
USER ACTION   "Masuk dengan Google" or email + password (Firebase client SDK)
→ ENDPOINT    POST /api/customer/auth/login {"id_token": await user.getIdToken()}
→ RESPONSE    200 {data:{actor:"customer", token, token_type, expires_at, customer}}
              · 401/403/503 {message, code} (see §15.10)
→ UI          Store token + expires_at securely; then PIN-unlock flow on later app starts
```

The web app should refuse `actor: "customer"` (and the mobile app `actor: "staff"`)
and tell the user to use the other app.

If a customer types NIS/account number + PIN (for example on an old login screen), call
`POST /api/customer/login`: it never returns a token. After a correct PIN it returns
`403 activation_required` (show "check your email", with the masked `email`, and offer
"send a new link" → `POST /api/customer/activation/resend`) or
`403 firebase_login_required` (show the Google / email sign-in). Wrong PINs count toward the
shared lock; the message says only "NIS/nomor rekening atau PIN salah." until the account is
locked, then it contains the unlock time.

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
→ RESPONSE    200 {data:{id, account_number, nis, full_name, class, group, major_name, account_status, balance, pin_locked_until, activation_status}} · 404
→ UI          Show a customer card; disable deposit/withdraw when account_status ≠ active;
              disable withdraw while pin_locked_until is in the future; show balance;
              activation_status "pending" (app not activated) → no mobile approval (§15.5)
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

Two ways, chosen by the `withdrawal_verification` setting (`GET /api/settings`):
`mobile` (default) → **A**; `terminal` → **B**; `both` → offer both (e.g. B as the
fallback for a customer without a phone).

**A** needs a customer who has activated the app (`activation_status: "activated"` from the
lookup). For a `pending` customer the backend refuses A with
`422 {"message": "...", "code": "customer_app_not_activated"}` instead of creating a request
that would only expire after 2 minutes:

| Mode | Customer `pending` | Show |
| --- | --- | --- |
| `mobile` | No withdrawal possible at the counter | Disable "Kirim ke aplikasi nasabah" with the hint "Nasabah belum mengaktifkan aplikasi"; the backend message: `Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga tidak dapat menyetujui penarikan melalui aplikasi. Minta nasabah menyelesaikan aktivasi melalui tautan di email undangan terlebih dahulu.` |
| `both` | Only **B** (PIN at the terminal) | Hide or disable A, show the PIN pad; the backend message for A: `Nasabah belum mengaktifkan aplikasi Bank Mini, sehingga tidak dapat menyetujui penarikan melalui aplikasi. Lakukan penarikan dengan PIN nasabah di terminal loket.` |
| `terminal` | Unaffected (B only) | — |

Disable up front from `activation_status`, and still handle the `422` code (the status can
change between lookup and request): show `message` as is.

**A. Customer approves in the mobile app (default)**

```text
TELLER        Enters amount → "Kirim ke aplikasi nasabah"
→ ENDPOINT    POST /api/teller/withdrawal-authorizations {"account_number":"RPL-2026-001","amount":30000}
→ RESPONSE    201 {data:{id, status:"pending", expires_at, server_time, customer, ...}} ·
              422 open request exists / PIN locked / balance / shift / status / mode disabled ·
              422 {message, code:"customer_app_not_activated"} (app not activated, see above)
→ UI          Waiting screen: amount, customer, countdown (expires_at − server_time), "Batalkan"
              Poll GET /api/teller/withdrawal-authorizations/{id} every 2 s
              On a reload: GET /api/teller/withdrawal-authorizations?status=open to resume

CUSTOMER      Opens the app (or is already on the "Persetujuan" screen)
→ ENDPOINT    GET /api/customer/withdrawal-authorizations   (poll every 3–5 s while visible)
→ UI          Card: amount + terbilang, teller name, balance → balance after, countdown;
              buttons "Tolak" and "Setujui" → masked 6-digit PIN pad
→ ENDPOINT    POST /api/customer/withdrawal-authorizations/{id}/approve {"pin":"......"}
→ RESPONSE    200 status "approved" · 422 wrong PIN (remaining_attempts; request stays pending) ·
              422 locked (pin_locked_until) · 422 with status (expired/cancelled/...)
              or POST .../{id}/reject → 200 status "rejected"

TELLER        Poll shows "approved" (countdown to approval_expires_at) → "Selesaikan penarikan"
→ ENDPOINT    POST /api/teller/withdrawal-authorizations/{id}/complete
→ RESPONSE    201 Transaction (withdrawal_authorization.method = "mobile") · 200 same transaction on retry ·
              422 approval expired / shift / balance
→ UI          Hand over cash, print the receipt. On rejected / expired / cancelled: show it
              and allow a new request
```

The teller's screen never shows a PIN field in this mode. Only one open request per
teller and per customer: cancel the old one before starting another.

**B. PIN typed at the teller terminal (`terminal` / `both`)**

```text
USER ACTION   Enters amount; customer types PIN on the confirmation screen
→ ENDPOINT    POST /api/teller/withdrawals  (header Idempotency-Key)
→ REQUEST     {"account_number":"RPL-2026-001","amount":30000,"pin":"123456"}
→ VALIDATION  mode allows terminal; as deposit + pin 6 digits; PIN correct & not locked (checked first);
              shift; status; balance − amount ≥ min_balance
→ RESPONSE    201 Transaction · 200 replay ·
              422 {"message":"PIN salah. Sisa percobaan: 2.","remaining_attempts":2,"pin_locked_until":null} ·
              422 locked (remaining_attempts 0, pin_locked_until) ·
              422 "Saldo tidak mencukupi ... penarikan maksimal RpX." ·
              422 "Penarikan harus disetujui nasabah melalui aplikasi..." (mode is mobile)
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
              digital card shows qr_payload as a QR code for the teller to scan;
              while the app is in the foreground, poll GET /api/customer/withdrawal-authorizations
              and show a pending request prominently (see §15.5 A)
```

### 15.10 Customer activation, login, unlock and password (Firebase)

Backend design, Firebase Console and SMTP setup: [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md).
No customer app exists yet; this is the contract for whichever client (Expo or web) hosts
these screens. Firebase calls below use the modular Firebase JS SDK names; native
libraries have equivalents.

**Configuration (client):** Firebase web config through the client's environment
(`VITE_FIREBASE_API_KEY`, `VITE_FIREBASE_AUTH_DOMAIN`, `VITE_FIREBASE_PROJECT_ID`,
`VITE_FIREBASE_APP_ID`, or `EXPO_PUBLIC_FIREBASE_*`), plus the Google web client ID for
native Google Sign-In. These values are public. The service account key is server-only
and must never be in a client.

**Activation page** (opened from the email link `CUSTOMER_ACTIVATION_URL?token=…`; the
URL must open this page in the app or the web):

```text
1. Read token from the URL. Do not log it or send it to analytics.
2. POST /api/customer/activation/inspect {token}
   200 → show full_name, account_number and the registered email (read-only), expires_at,
         and the two options below.
   404 activation_invalid → "Tautan tidak valid atau sudah dipakai" (+ "Masuk" if they already activated)
   410 activation_expired → "Tautan kedaluwarsa"; offer "Kirim tautan baru"
       (NIS + PIN → POST /api/customer/activation/resend) or "hubungi petugas".

Option A — Continue with Google
3. Sign in with Google (web: signInWithPopup(auth, provider) with
   provider.setCustomParameters({login_hint: registeredEmail}); native: Google Sign-In
   library → GoogleAuthProvider.credential(idToken) → signInWithCredential).
4. If user.email ≠ registered email (compare lowercase): signOut(), explain which address
   to use. (The backend would refuse it anyway with 403 email_mismatch.)
5. POST /api/customer/activation/complete {token, id_token: await user.getIdToken()}

Option B — Create a password
3. Show the registered email as fixed text (never an editable field) and a password +
   confirmation (follow the project's Firebase password policy).
4. createUserWithEmailAndPassword(auth, registeredEmail, password)
   - auth/email-already-in-use: an account with this email exists → ask them to sign in
     with it (password, or Google if that is how it was created) and continue at step 7.
   - auth/weak-password → show the rule.
5. sendEmailVerification(user, {url: <this activation page URL with the token>})
   → screen "Cek email Anda, lalu tekan Saya sudah verifikasi".
6. On that button (or when returning from the link): await user.reload();
   if !user.emailVerified → keep waiting (offer resend after 60 s).
7. id_token = await user.getIdToken(true)   ← force refresh so the token carries email_verified
8. POST /api/customer/activation/complete {token, id_token}

Both options
   200 → store data.token + data.expires_at securely; go to the app (ask the customer to
         keep their PIN private; the PIN is the one given by the bank, changeable in-app).
   403 email_not_verified → back to step 5/6 (the link stays usable)
   403 email_mismatch     → signOut(); "Gunakan akun <masked email>" (link stays usable)
   409 identity_already_linked → this Google/email account already owns another account;
       signOut() and use another account / contact staff
   401 firebase_token_invalid → sign in again
   503 auth_unavailable   → retry later
```

**Existing Firebase account / provider conflicts.** With "one account per email"
(project default), signing in with Google for an email that already has a password
account normally returns the same Firebase user. If the client gets
`auth/account-exists-with-different-credential`, ask the user to sign in with the existing
method first, then call `linkWithCredential(user, pendingCredential)`. Never link or choose
an account because an email matches; the backend only trusts the UID it bound at
activation.

**Login**

```text
signInWithEmailAndPassword(auth, email, password)  or Google (as above)
→ POST /api/customer/auth/login {id_token: await user.getIdToken()}
   200 → store token + expires_at
   403 account_not_linked → "Akun ini belum terhubung ke rekening": activation is done from
       the email link; offer the NIS + PIN "kirim tautan baru" flow
   403 email_not_verified → sendEmailVerification(), then retry with getIdToken(true)
   403 account_inactive   → "Rekening sudah ditutup"; signOut()
   401 firebase_token_invalid → signOut(); sign in again
   503 auth_unavailable   → retry later
```

With email enumeration protection on, Firebase sign-in errors do not say whether the
email exists (`auth/invalid-credential`); show one generic "email atau password salah".

**Session and PIN unlock**

- Keep `token` and `expires_at` in secure storage (Expo: `expo-secure-store`).
- On a cold start or when returning from the background (choose an idle time, e.g. 5
  minutes): if `expires_at` has passed → sign-in screen; otherwise show the PIN pad →
  `POST /api/customer/unlock {pin}`:
  `200` → continue; `422` with `remaining_attempts` → "PIN salah, sisa N";
  `422` with `pin_locked_until` → show the unlock time; `401` → session over → sign-in.
- When the backend session expires (`401`), call Firebase `signOut()` and require a full
  sign-in. Do not silently exchange a cached Firebase session for a new backend session;
  that would make the session effectively endless.
- Withdrawal approval keeps its own PIN entry (§15.5 A).

**Logout:** `POST /api/customer/logout` (or `logout-all` for "keluar dari semua
perangkat"), then Firebase `signOut()`, then delete the stored token, even if the request
failed.

**Forgot password** (email/password accounts): `sendPasswordResetEmail(auth, email)`.
Always show the same message ("Jika email terdaftar, tautan reset telah dikirim"),
whatever Firebase returns, so the screen does not reveal which emails exist. Firebase sends
the email (its template, §6 of CUSTOMER_AUTH.md). The bank PIN is unrelated: a forgotten PIN
is reset by an administrator.

**Change password** (signed-in, provider `password`): ask for the current and new
password → `reauthenticateWithCredential(user, EmailAuthProvider.credential(user.email, current))`
→ `updatePassword(user, newPassword)`. Handle `auth/invalid-credential` /
`auth/wrong-password` (current password wrong), `auth/weak-password`,
`auth/requires-recent-login` (re-authenticate, then retry), `auth/too-many-requests`.
Google accounts have no password here ("Dikelola oleh Google"). A password change revokes
the user's other Firebase sessions but **not** backend sessions on other devices; offer
"keluar dari semua perangkat" (`logout-all`) afterwards.

**Error codes** (all with `message`; show `message` unless the UI has its own text):

| `code` | Status | Where | UI |
| --- | --- | --- | --- |
| `activation_required` | 403 | NIS + PIN check | "Cek email" + masked `email`; resend option (`email` null → contact staff) |
| `firebase_login_required` | 403 | NIS + PIN check, resend | Show Google / email sign-in |
| `activation_invalid` | 404 | activation | Invalid/used link |
| `activation_expired` | 410 | activation | Expired; request a new link |
| `email_not_verified` | 403 | activation, login | Verify email, retry with `getIdToken(true)` |
| `email_mismatch` | 403 | activation | Use the registered (masked `email`) account |
| `identity_already_linked` | 409 | activation | Account belongs to another customer |
| `account_not_linked` | 403 | login | Not activated with this account |
| `account_inactive` | 403 | login | Account closed |
| `provider_not_allowed` | 403 | activation, login | Only Google / email |
| `firebase_token_invalid` | 401 | activation, login | Sign in again |
| `auth_unavailable` | 503 | activation, login | Try again later |
| `already_activated` / `email_missing` | 409 / 422 | admin invitation | Admin UI messages |

**Admin screens:** the create-customer form needs `email` (show `invitation.mail_sent`;
when `false`, offer "Kirim ulang undangan"). Show `activation_status` in the list
(`GET /customers?activation=pending`) and detail, a "Kirim ulang undangan" button for
pending accounts with an email (`POST /admin/customers/{id}/invitation`), and lock the email
field when `activation_status = activated`.

## 16. Pagination, filtering, sorting

- Paginated endpoints: `GET /staff`, `/customers`, `/teller/transactions`,
  `/teller/daily-reports`, `/supervisor/daily-reports`, `/supervisor/cash-transfers`,
  `/audit/transactions`, `/audit/journals`, `/customer/transactions`,
  `/teller/withdrawal-authorizations`.
- Not paginated: `GET /majors`, shift/report `transactions` arrays,
  `GET /customer/withdrawal-authorizations`.
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
| L2 | Terminal withdrawal (mode B) checks the PIN **before** the shift, account status and balance. A wrong PIN is counted even when the withdrawal would have failed anyway. Mobile approval is pre-checked at request creation. | In mode B, pre-check shift (`can_transact`), status, balance and lock before asking for the PIN |
| L3 | After a rejection, the reopened shift still shows the previous `physical_balance`, `difference` and `closing_counts` until it is closed again (verified). | For `status = rejected`, ignore those fields or label them "previous count" |
| L4 | CORS exposes no response headers, so a browser app on another origin cannot read `Idempotent-Replayed` or `Content-Disposition`. | Use status `201` vs `200` to detect replays; name downloaded PDFs yourself; or serve the web app same-origin / via a dev proxy |
| L5 | Graduating (deactivating) an account does not require a zero balance, cannot be undone via the API, and closed accounts cannot be transacted. With a minimum balance > 0 the last Rp10.000 can never be withdrawn. | Warn before deactivation when balance > 0 |
| L6 | No self-service password change for staff. Admin accounts cannot be edited at all through the API (including password). | No "change my password" page for staff |
| L7 | No token refresh; staff tokens expire 8 hours after login, customer tokens after `CUSTOMER_SESSION_MINUTES` (default 30 days), both absolute. | Re-login on `401` |
| L8 | No "forgot PIN" (an admin resets the PIN) and no staff password reset. Customer passwords are Firebase's: reset and change in the client (§15.10). | Staff/PIN: show "contact the administrator" |
| L9 | Mixed languages: business messages Indonesian, field-validation messages English. | Map field errors if a single language is needed |
| L10 | Annual report, journal report and audit export exist only as PDF (no JSON equivalent for the annual summary). | Show PDFs; build on-screen tables from `/audit/*` JSON |
| L11 | Filter values on `/customers` and `/staff` are not validated. | Use dropdowns |
| L12 | `qr_scanner_mode` is informational only; lookup accepts QR payloads even when the mode is `disabled`. | The UI decides what to offer |
| L13 | Majors cannot be created or edited through the API (seeded). | Read-only dropdown |
| L14 | The teller's read access to accounts is a single-customer lookup; there is no account list for tellers. | No account browsing for tellers |
| L15 | `customers.balance` is a 32-bit integer (max Rp2.147.483.647). | Not a practical concern for a school |
| L16 | Idempotency keys never expire; reusing an old key for a new transaction returns `422`. | Always generate a fresh key per new transaction |
| L17 | The QR payload is the plain account number (not signed); it identifies but does not authorize. Withdrawals still need the customer's PIN (in the app or at the terminal). | Never treat a scan as authorization |
| L19 | Withdrawal requests are delivered by polling; there are no push notifications. The customer must have the app open (or refresh) to see a request. | Tell the customer to open the "Persetujuan" screen; poll while visible |
| L20 | Any device where the customer is logged in can approve a request (tokens have no abilities). | Optionally ask for device biometrics before showing the approval PIN pad |
| L18 | No sort parameters on any list; orders are fixed per endpoint (see §16). | Sort client-side within a page only, or accept the server order |
| L21 | Revoking a user in the Firebase console, or a Firebase password change, does not end backend sessions that already exist (Firebase is checked at login and activation only). | Offer `logout-all` after a password change; admins deactivate to cut access |
| L22 | The PIN unlock is enforced by the app; the API accepts a valid session token without a recent unlock. | Always gate the UI with the PIN after the idle time |
| L23 | Invitation and "account activated" emails are sent synchronously; a mail failure returns `mail_sent: false` but keeps the account. | Show the flag; offer resend |

## 19. Known inconsistencies or unclear behavior

| # | Item | Status |
| --- | --- | --- |
| I1 | The UKK soal's RBAC matrix gives Admin no access to "input transaksi" and read access to the journal. The backend also lets Admin read the transaction log (`/audit/transactions*`). Reading is not input, but a strict reading of the matrix could disagree. | Implemented as described; decide whether the admin UI shows the log |
| I2 | `POST /staff/login` and `POST /customer/auth/login` duplicate `POST /login`. | Use `/login` only (or the customer alias) |
| I3 | The customer login message `Rekening ini sudah tidak aktif.` (shown only after a correct PIN) can practically never appear: `inactive` is only set by deactivation, which also soft-deletes, and soft-deleted accounts get the generic "wrong NIS/PIN" message. | Treat both as login failure |
| I4 | `docs/API.md` described `physical_balance`/`difference` as null while a shift is open, which is wrong for rejected shifts (L3). | Corrected in API.md |
| I5 | `docs/API.md`'s access matrix showed Admin with no access in the deposit/withdrawal row although Admin can read the audit log. | Corrected in API.md |
| I7 | PRD FR-Auth-02 says students sign in with NIS/account number + PIN. By decision, customers now sign in with Firebase (Google or email/password) after activation, and the PIN unlocks the app and authorizes withdrawals; NIS + PIN alone no longer starts a session. | Implemented as described; see CUSTOMER_AUTH.md A2 |
| I6 | The UKK interview asked for Customer Service / Accounting roles and student vs employee savings products; the backend implements the soal's four roles and one savings product (account 201). | Not implemented; do not build UI for them |

## 20. Not implemented (do not build UI for these)

Customer self-registration (accounts are created by an administrator and activated from the invitation) · customer-initiated transactions or transfers between
accounts (customers only approve teller-started withdrawals) · loans, term deposits,
fees · notifications/push (use polling) · approval in the app for deposits or corrections · "forgot PIN"
(admin reset only) · staff password reset · staff self-service profile/password · restoring graduated accounts ·
major management · multi-role users · attendance (absensi) integration · bulk
approval of reports · editing or deleting transactions · JSON annual report ·
server-generated QR images.
