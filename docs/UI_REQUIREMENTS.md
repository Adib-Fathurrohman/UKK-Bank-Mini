# Functional UI Requirements

Pages and features that the **existing API can support**, per role. This describes
function, data and states, not visual design. Every endpoint below exists (see
[RBAC.md](RBAC.md#7-endpoint-restrictions)). Features the backend does not support
are listed at the end and must not be built.

Common requirements for every page:

- **States:** loading, error (show `message`), empty, and success.
- **Auth:** send the bearer token; on `401` or a role `403`, clear the token and go to
  the login page (UKK rubric 3.2).
- **Money** shown as `Rp1.234.567` from integer values; **times** converted from UTC to
  WIB.
- **Language:** the users are Indonesian students and school staff; business
  messages from the API are already Indonesian.
- **Lists** use `page`/`per_page` pagination; there are no sort parameters.

---

## Shared (staff web app)

### Login
- **Purpose:** sign in staff.
- **Role:** public.
- **Data / endpoints:** `POST /login` with `username`, `password`.
- **States:** submitting (button disabled), field errors (`422`), too many attempts
  (`429`), blocked account message.
- **Actions:** submit; on success store the token and route by `user.role`; refuse
  `actor: "customer"` with a message to use the mobile app.

### App shell / profile
- **Purpose:** navigation per role, current user, logout.
- **Data / endpoints:** `GET /staff/me` on load; `POST /staff/logout`.
- **States:** validating stored token; logged out.
- **Permissions:** menu items per [RBAC.md](RBAC.md#5-capability-matrix).

---

## Admin

### Admin dashboard
- **Purpose:** quick overview.
- **Data / endpoints:** counts from list `meta.total` with `per_page=1`:
  `GET /customers?status=active`, `?status=suspended`, `?trashed=only`;
  `GET /staff?role=teller`, `?role=supervisor`; trial balance
  `GET /audit/journals/summary?from=<today>`. No dedicated dashboard endpoint exists.
- **States:** partial failure per widget.

### Staff list
- **Purpose:** find and manage tellers and supervisors.
- **Data / endpoints:** `GET /staff` with `status` (`active`/`inactive`/`all`),
  `role`, `search`, pagination.
- **Actions:** open detail/edit, block, unblock, delete, create, import.
- **States:** empty result; admin rows read-only (the API refuses changes to admin
  accounts).

### Staff create / edit
- **Purpose:** create or change a teller/supervisor.
- **Data / endpoints:** `POST /admin/staff`; `GET /staff/{id}`;
  `PATCH /admin/staff/{id}`. Fields: username, full_name, password (required on
  create, optional on edit), role (`teller`/`supervisor`), class, phone.
- **States:** field errors; `403` when editing an admin (show as an error, do not log
  out).
- **Permissions:** admin.

### Staff block / unblock / delete
- **Endpoints:** `POST /admin/staff/{id}/block`, `.../unblock`, `DELETE /admin/staff/{id}`.
- **States:** confirmation; delete `409` → explain that staff with history can only be
  blocked and offer Block.

### Staff import
- **Purpose:** create many tellers from the piket list.
- **Data / endpoints:** `POST /admin/staff/import` (multipart `file`, `.xlsx`/`.csv`,
  ≤ 2 MB, ≤ 500 rows). Accepted headers include Indonesian names (Nama, Kelas, No WA).
- **States:** uploading; `422` with row errors (`rows.{i}.{field}`, sheet row = i+2),
  nothing created; success list with any **generated passwords shown once** (allow
  printing/copying them).

### Customer list
- **Purpose:** browse accounts.
- **Data / endpoints:** `GET /customers` with `search`, `major_id` (from
  `GET /majors`), `class`, `group`, `status`, `trashed`.
- **Actions:** open detail, create.

### Customer detail
- **Data / endpoints:** `GET /customers/{id}` (closed accounts too). History via
  `GET /audit/transactions?customer_id={id}`. Statement PDF
  `GET /customers/{id}/statement?from=&to=`.
- **Actions:** edit, reset PIN, suspend/activate, print card, deactivate.
- **States:** closed account (`deleted_at` set): read-only.

### Open account
- **Endpoints:** `GET /majors`, `POST /admin/customers` (nis, full_name, class,
  major_id, group, pin).
- **States:** field errors; success shows the generated account number.

### Edit account / reset PIN / suspend
- **Endpoint:** `PATCH /admin/customers/{id}` (any of nis, full_name, class, major_id,
  group, `account_status` active/suspended, pin).
- **Notes:** a new PIN also clears a PIN lock; account number and balance are not
  editable.

### Print customer card
- **Endpoint:** `GET /admin/customers/{id}/card`.
- **Function:** render `qr_payload` as a QR code with name, account number, NIS,
  class/major; browser print. (The backend does not produce the card image or PDF.)

### Graduate (deactivate) account
- **Endpoint:** `POST /admin/customers/{id}/deactivate`.
- **States:** strong confirmation; warn when `balance > 0` (the balance stays and
  cannot be withdrawn afterwards; there is no undo).

### Settings
- **Endpoints:** `GET /settings`, `PATCH /admin/settings` (`min_balance` 0–1,000,000,
  `qr_scanner_mode` webcam/usb/disabled).

### Audit log
- **Endpoints:** `GET /audit/transactions` (filters `from`, `to` with optional time,
  `teller`, `customer`, `type`), `GET /audit/transactions/{id}` (journal lines,
  correction links), PDF `GET /audit/transactions/export`.
- **States:** filtered empty result.

### Journal and trial balance
- **Endpoints:** `GET /audit/journals` (+ `account_code`, `position`,
  `transaction_id`), `GET /audit/journals/summary` (`is_balanced`), PDF
  `GET /audit/journals/export`.

### Reports
- **Endpoints:** `GET /reports/annual?school_year=` (PDF; default current school year),
  plus the exports above.
- **States:** downloading; JSON error instead of a PDF.

---

## Supervisor

### Supervisor dashboard
- **Data / endpoints:** pending reports `GET /supervisor/daily-reports?status=submitted`
  (`meta.total`), held reports `?status=on_hold`, running shifts `?status=open`,
  today's vault total `GET /supervisor/cash-transfers?from=<today>` (`total_amount`),
  today's trial balance `GET /audit/journals/summary?from=<today>`.
- **Notes:** pending items are shown as lists; there are no notifications.

### Daily report list
- **Endpoint:** `GET /supervisor/daily-reports` with `status`, `date`, `teller_id`
  (teller options from `GET /staff?role=teller`).
- **States:** open shifts show live totals.

### Daily report review
- **Purpose:** verify one report against the slips and the cash.
- **Data / endpoints:** `GET /supervisor/daily-reports/{id}` (totals, opening/closing
  counts, difference, transactions with corrections, teller); PDF
  `GET /supervisor/daily-reports/{id}/export`.
- **Actions:** approve (`note` optional), hold (`note` required), reject (`note`
  required).
- **States:** actions available only for `submitted` (all three) and `on_hold`
  (approve, reject); `approved` is read-only; reject may fail if the teller runs another
  shift (suggest Hold). Confirmation before approve (final, moves cash to the vault).
- **Permissions:** supervisor; one report at a time.

### Vault ledger
- **Endpoints:** `GET /supervisor/cash-transfers` (`from`, `to`, `teller_id`,
  `total_amount`), `GET /supervisor/cash-transfers/{id}`.

### Accounts (read-only)
- **Endpoints:** `GET /customers`, `GET /customers/{id}`, statement PDF.
- **Permissions:** no create/edit/deactivate/card actions.

### Staff (read-only)
- **Endpoints:** `GET /staff`, `GET /staff/{id}`.

### Audit log, journal, reports
- Same as the admin pages.

---

## Teller

### Shift status / open shift
- **Purpose:** start the working day.
- **Data / endpoints:** `GET /teller/shift`; `POST /teller/shift/open` with a
  denomination count form (100000 … 100) showing the running total.
- **States:**
  - no shift and `closed_today = false` → open form;
  - no shift and `closed_today = true` → "closed for today", no transactions;
  - shift with `can_transact = false` → an earlier day's shift must be closed first;
  - `rejected` → show `supervisor_note`; corrections and re-close allowed.

### Counter (lookup + transaction)
- **Purpose:** serve a customer.
- **Data / endpoints:** `GET /settings` (`qr_scanner_mode`, `min_balance`),
  `GET /teller/customers/lookup?identifier=`, `POST /teller/deposits`,
  `POST /teller/withdrawals`.
- **Functions:**
  - QR scan via camera (`webcam`), keyboard-wedge scanner input (`usb`), or manual
    entry (`disabled`), always with a manual fallback field;
  - customer panel: name, account number, NIS, class/major, status, balance, PIN lock;
  - amount input (integer, **multiples of 100**, ≤ 100,000,000);
  - withdrawal pre-check: `balance − amount ≥ min_balance` before showing the PIN pad;
  - confirmation dialog summarizing type, account, name, amount (and amount in words);
  - customer PIN pad for withdrawals (masked, cleared after each attempt; show
    `remaining_attempts` / lock time);
  - `Idempotency-Key` per confirmed submission, reused on retry; submit disabled while
    pending;
  - keyboard operation (Enter to confirm) as requested in the interview.
- **States:** shift not open → counter disabled; account `suspended` → transactions
  disabled; PIN locked → withdraw disabled; `422` business message; success with new
  balance and receipt option.
- **Permissions:** teller.

### Receipt
- **Endpoint:** `GET /teller/transactions/{id}/receipt` (PDF, own transactions).
- **Function:** download/print; handle a JSON error body.

### Today's transactions
- **Endpoint:** `GET /teller/transactions?date=` (own, newest first).
- **Actions:** reprint receipt; correction (only rows of type `deposit`/`withdrawal`,
  `corrected_by` null, `daily_report_id` = active shift id).

### Correction
- **Endpoint:** `POST /teller/transactions/{id}/correct` (`reason`,
  `supervisor_username`, `supervisor_password`, `Idempotency-Key`).
- **States:** supervisor credential error on `supervisor_password`; already corrected;
  wrong shift; `429`. After success, prompt to record the correct amount.

### Close shift
- **Endpoints:** `GET /teller/shift` (system balance, totals, transactions),
  `POST /teller/shift/close` (denomination count).
- **States:** live comparison of counted total vs `system_balance` before submitting;
  `422` mismatch shows `difference` and keeps the form; success → submitted, offer PDF.
- **Note:** for `rejected` shifts ignore the old `physical_balance`/`difference`.

### Shift history
- **Endpoints:** `GET /teller/daily-reports`, `GET /teller/daily-reports/{id}`, PDF
  `GET /teller/daily-reports/{id}/export` (only `submitted`, `on_hold`, `approved`).

---

## Mobile User (customer, React Native + Expo)

### Login
- **Endpoint:** `POST /login` with `identifier` (NIS or account number) and `pin`
  (6-digit numeric keypad).
- **States:** field errors, wrong credentials, `429`; refuse `actor: "staff"`.
- **Storage:** token in `expo-secure-store`.

### Home / balance
- **Endpoints:** `GET /customer/balance`, `GET /customer/transactions?per_page=5`.
- **Data:** balance, minimum balance, status, `as_of`, latest lines.
- **States:** `suspended` notice (can view, cannot transact at the counter); pull to
  refresh.

### Transaction history
- **Endpoint:** `GET /customer/transactions` with `from`, `to`, `type`, `page`.
- **Data:** description, direction in/out, amount, balance after, time (WIB).
- **States:** infinite scroll, empty period.

### Transaction detail
- **Endpoint:** `GET /customer/transactions/{id}`.

### Digital card
- **Endpoint:** `GET /customer/card`.
- **Function:** render `qr_payload` as a QR code with name, account number, NIS,
  class/major, for the teller to scan.

### Profile
- **Endpoint:** `GET /customer/me`.

### Change PIN
- **Endpoint:** `POST /customer/pin` (`current_pin`, `new_pin`,
  `new_pin_confirmation`).
- **States:** wrong current PIN with `remaining_attempts`; lock message; validation
  (must differ, must match).

### Statement download
- **Endpoint:** `GET /customer/statement/export?from=&to=` (PDF).
- **Function:** download and open/share with the platform viewer.

### Logout
- **Endpoint:** `POST /customer/logout`; always delete the stored token.

---

## Not supported by the API (do not build)

- Staff "change my password" or profile editing; any admin-account management.
- Forgot password / forgot PIN.
- Customer-initiated transfers or payments; deposits/withdrawals from the mobile app.
- Restoring closed accounts; major management.
- Bulk approval; notifications.
- JSON annual report or dashboard statistics endpoints (compose from lists).
- Editing or deleting transactions, reports or transfers.
- Multiple savings products, loans, fees.
