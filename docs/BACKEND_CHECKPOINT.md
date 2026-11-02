# Backend Checkpoint

State of the E-Teller Bank Mini Laravel backend at the handoff to frontend
development. First written at commit `5ddbd69`; updated for the mobile withdrawal
approval (Mode A of [PIN_VERIFICATION.md](PIN_VERIFICATION.md)) on top of `c405986`,
and for the Firebase customer login and activation
([CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)) on top of `4610046`, on branch `main`.

## Backend status

**Ready for frontend integration.** All API endpoints the frontends need exist,
are covered by automated tests (624 passing on SQLite and on MariaDB), and are
documented from the implementation. No further backend feature work is planned
before frontend development starts.

What is **not** finished is listed honestly below: the
[known limitations](#known-backend-limitations) (behaviors the frontend must work
around), and the [repository items](#repository-and-environment-items-outside-the-api)
that sit outside the API.

## Technology

Versions from `composer.json` / `composer.lock`:

| Item | Value |
| --- | --- |
| Framework | Laravel `laravel/framework` v13.24.0 (constraint `^13.8`) |
| PHP | `^8.3` (constraint in `composer.json`) |
| Database | MariaDB (`mariadb` driver, InnoDB engine pinned in `config/database.php`). Server version not pinned in the repository. Note: `.env.example` defaults to `DB_CONNECTION=sqlite`; the README instructs MariaDB. Tests use in-memory SQLite (`phpunit.xml`) |
| Authentication | Laravel Sanctum v4.3.3 personal access tokens (bearer): staff 480-minute expiry, customers `CUSTOMER_SESSION_MINUTES` (default 30 days). Customer identity: Firebase Authentication through the Firebase Admin SDK `kreait/firebase-php` v8.6.0 |
| Mail | Laravel mail (SMTP in production; `MAIL_MAILER=log` locally) for the invitation and "account activated" emails |
| API architecture | JSON REST under `/api`, Laravel API Resources (`{data: ...}`), form-request validation, service classes for business rules, route middleware for authorization |
| PDF | barryvdh/laravel-dompdf v3.1.2 (dompdf v3.1.6) |
| Spreadsheet import | openspout/openspout v5.13.0 |
| Tests | Pest v5.0.3 |

## Available roles

| Role | Model | Client |
| --- | --- | --- |
| `admin` | `users.role` | Staff web app |
| `supervisor` | `users.role` | Staff web app |
| `teller` | `users.role` | Staff web app |
| Customer (nasabah) | `customers` table, separate model, no role value | Mobile app |

One role per staff member; multi-role is not implemented. Details: [RBAC.md](RBAC.md).

## Core capabilities

- Single login gateway for all roles; Sanctum bearer tokens; logout; current user.
- Staff management: create, edit, block, unblock, delete (only without history),
  spreadsheet import with generated passwords; teller identity (class, phone).
- Customer accounts: open with generated number `RPL-2026-001`, edit, suspend,
  reset PIN, graduate (inactive + soft delete), card data with QR payload.
- Teller shift: open with a denomination count, live totals, close with a physical
  count that must match the system balance, shift history.
- Customer lookup by scanned QR payload, account number or NIS.
- Deposits and withdrawals with row locking, minimum balance and idempotency keys.
- Withdrawal authorization: by default the teller creates a request, the customer
  approves it in the mobile app with their PIN, the teller completes it (polling,
  120 s windows, exactly-once completion). PIN at the teller terminal is an optional
  mode (`withdrawal_verification` setting).
- One PIN lock (3 wrong → 15 minutes) shared by customer login, approval, terminal
  withdrawal and PIN change.
- Corrections (reversal transactions) authorized by a supervisor's credentials.
- Double-entry journal (101 Kas / 201 Tabungan) written automatically.
- Supervisor review: approve (moves cash to the vault), hold, reject (reopens shift);
  vault ledger.
- Audit: transaction log and journal with date-time, teller and customer filters;
  trial balance.
- PDFs: receipt, daily report (locked until reconciled), customer statement, journal
  report, audit trail, annual report.
- Mobile: balance, statement, transaction detail, digital card, PIN change,
  statement PDF, approve/reject withdrawal requests.
- Admin settings: minimum balance, QR scanner mode, withdrawal verification mode.

## API status

**74 routes** (73 distinct paths; `PUT|PATCH` share a route). Machine-readable index:
[API_SUMMARY.json](API_SUMMARY.json). Full reference: [API.md](API.md).

| Group | Count | Endpoints |
| --- | --: | --- |
| Authentication | 10 | `POST /login`, `POST /staff/login`, `POST /customer/auth/login`, `POST /customer/login` (NIS + PIN check, no token), `GET /staff/me`, `POST /staff/logout`, `GET /customer/me`, `POST /customer/logout`, `POST /customer/logout-all`, `POST /customer/unlock` |
| Customer activation | 3 | `POST /customer/activation/inspect`, `POST /customer/activation/complete`, `POST /customer/activation/resend` |
| Accounts (staff side) | 8 | `GET /customers`, `GET /customers/{id}`, `POST /admin/customers`, `PUT/PATCH /admin/customers/{id}`, `POST /admin/customers/{id}/deactivate`, `POST /admin/customers/{id}/invitation`, `GET /admin/customers/{id}/card`, `GET /teller/customers/lookup` |
| Customer / student (mobile) | 6 | `GET /customer/balance`, `GET /customer/card`, `GET /customer/transactions`, `GET /customer/transactions/{id}`, `GET /customer/statement/export`, `POST /customer/pin` |
| Withdrawal approval (customer) | 4 | `GET /customer/withdrawal-authorizations`, `GET /customer/withdrawal-authorizations/{id}`, `POST .../{id}/approve`, `POST .../{id}/reject` |
| Withdrawal approval (teller) | 5 | `POST /teller/withdrawal-authorizations`, `GET /teller/withdrawal-authorizations`, `GET /teller/withdrawal-authorizations/{id}`, `POST .../{id}/cancel`, `POST .../{id}/complete` |
| Transactions and shift (teller) | 8 | `POST /teller/deposits`, `POST /teller/withdrawals`, `GET /teller/transactions`, `GET /teller/transactions/{id}/receipt`, `POST /teller/transactions/{id}/correct`, `GET /teller/shift`, `POST /teller/shift/open`, `POST /teller/shift/close` |
| Teller shift history | 3 | `GET /teller/daily-reports`, `GET /teller/daily-reports/{id}`, `GET /teller/daily-reports/{id}/export` |
| Staff / admin | 8 | `GET /staff`, `GET /staff/{id}`, `POST /admin/staff`, `POST /admin/staff/import`, `PUT/PATCH /admin/staff/{id}`, `DELETE /admin/staff/{id}`, `POST /admin/staff/{id}/block`, `POST /admin/staff/{id}/unblock` |
| Settings | 2 | `GET /settings`, `PUT/PATCH /admin/settings` |
| Reference data | 1 | `GET /majors` |
| Supervisor | 8 | `GET /supervisor/daily-reports`, `GET /supervisor/daily-reports/{id}`, `GET .../{id}/export`, `POST .../{id}/approve`, `POST .../{id}/hold`, `POST .../{id}/reject`, `GET /supervisor/cash-transfers`, `GET /supervisor/cash-transfers/{id}` |
| Reporting and audit | 8 | `GET /audit/transactions`, `GET /audit/transactions/{id}`, `GET /audit/transactions/export`, `GET /audit/journals`, `GET /audit/journals/summary`, `GET /audit/journals/export`, `GET /reports/annual`, `GET /customers/{id}/statement` |
| **Total** | **74** | |

Other routes outside `/api`: `GET /` (Laravel welcome page) and `GET /up` (health
check). Neither is used by the frontends.

## Known backend limitations

Copied from [FRONTEND_HANDOFF.md §18](FRONTEND_HANDOFF.md#18-known-backend-limitations).
They are current behavior and were not fixed; items marked *verified* were confirmed by
running the code.

| # | Limitation | Frontend impact |
| --- | --- | --- |
| L1 | **Odd amounts / Rp100 denominations.** Amounts are not required to be multiples of 100, but cash can only be counted in denominations ≥ Rp100. Any amount like Rp150 makes the shift impossible to close until that transaction is corrected (*verified*). | Enforce multiples of 100 in the teller UI |
| L2 | **Wrong PIN lock behavior (terminal mode only).** `POST /teller/withdrawals` checks the PIN before the shift, account status and balance. A wrong PIN is counted toward the 3-attempt lock even when the withdrawal would have failed anyway. Mobile approval pre-checks these when the request is created. | In terminal mode, pre-check shift (`can_transact`), status, balance and `pin_locked_until` before asking for the PIN |
| L3 | **Reopened/rejected shift stale values.** After a rejection, the reopened shift still shows the previous `physical_balance`, `difference` and `closing_counts` until it is closed again (*verified*). | For `status = rejected`, ignore those fields or label them "previous count" |
| L4 | **CORS response headers.** CORS exposes no response headers, so a browser app on another origin cannot read `Idempotent-Replayed` or `Content-Disposition`. | Use status `201` vs `200` to detect replays; name PDFs on the client; or serve the web app same-origin / through a dev proxy |
| L5 | **Graduation balance.** Deactivating an account does not require a zero balance, cannot be undone via the API, and closed accounts cannot be transacted. With a minimum balance > 0, the last Rp10.000 can never be withdrawn. | Warn before deactivation when balance > 0 |
| L6 | **Staff password limitations.** No self-service password change for staff. Admin accounts cannot be edited at all through the API (including password). An admin can set tellers' and supervisors' passwords. | No "change my password" page for staff |
| L7 | **No token refresh.** Tokens expire 8 hours after login (absolute); no refresh endpoint. | Re-login on `401` |
| L8 | **No password/PIN recovery.** No forgot-password or forgot-PIN flow; customer PINs are reset by an admin. | Show "contact the administrator" |
| L9 | Mixed languages: business messages Indonesian, field-validation messages English. | Map field errors if a single language is needed |
| L10 | **Annual report PDF-only.** Annual report, journal report and audit export exist only as PDF; no JSON annual summary. | Show PDFs; build on-screen tables from `/audit/*` JSON |
| L11 | **Unvalidated filter values** on `/customers` (`status`, `class`, `group`, `major_id`) and `/staff` (`role`, `status`): unknown values return empty lists or defaults instead of `422`. | Use dropdowns with fixed values |
| L12 | **Scanner-mode limitation.** `qr_scanner_mode` is stored and returned only; lookup accepts QR payloads even when the mode is `disabled`. | The UI decides what to offer per mode |
| L13 | Majors cannot be created or edited through the API (seeded). | Read-only dropdown |
| L14 | The teller's read access to accounts is a single-customer lookup; no account list for tellers. | No account browsing for tellers |
| L15 | `customers.balance` is a 32-bit integer (max Rp2.147.483.647). | Not a practical concern for a school |
| L16 | Idempotency keys never expire; reusing an old key for a new transaction returns `422`. | Generate a fresh key per new transaction |
| L17 | The QR payload is the plain account number (not signed); it identifies but does not authorize. | Withdrawals always need the customer's PIN (app or terminal) |
| L18 | **No sort parameters** on any list; orders are fixed per endpoint. | Sort client-side within a page only, or accept the server order |
| L19 | **Polling only** for withdrawal requests; no push. The customer must have the app open or refresh. | Poll while visible; tell the customer to open the approval screen |
| L20 | **Customer tokens have no abilities**: any device where the customer is logged in can approve. | Optional device biometrics before the approval PIN pad |
| L21 | An account graduated between approval and completion makes `complete` answer `404`; the request then expires. | Show the message; start over |

The login PIN gap found in the PIN audit (customer login was not covered by the PIN
lock) is **closed**: login now uses the shared lock.

Unclear or inconsistent behavior is listed in
[FRONTEND_HANDOFF.md §19](FRONTEND_HANDOFF.md#19-known-inconsistencies-or-unclear-behavior).

## Frontend integration requirements

Read before implementing:

1. **Start with** [FRONTEND_HANDOFF.md](FRONTEND_HANDOFF.md), then [RBAC.md](RBAC.md);
   use [API_SUMMARY.json](API_SUMMARY.json) as the endpoint index and [API.md](API.md) for
   examples.
2. **Login** only through `POST /api/login`; route by `data.actor` and `data.user.role`.
   The web app is for staff, the mobile app for customers.
3. **Token:** send `Authorization: Bearer <token>` and `Accept: application/json`.
   On `401` or a role `403`, delete the token and show the login page (UKK rubric 3.2).
   Store it in `expo-secure-store` on mobile.
4. **Money endpoints** (`deposits`, `withdrawals`, `correct`): send an
   `Idempotency-Key`, disable resubmission while pending, reuse the key on retry,
   accept `201` (new) or `200` (replay).
5. **Teller flow** depends on the shift: read `GET /teller/shift` first; open, transact,
   close. Enforce multiples of Rp100 (L1). Withdrawals follow the
   `withdrawal_verification` setting: in `mobile` mode create a request, poll, complete
   ([FRONTEND_HANDOFF.md §15.5](FRONTEND_HANDOFF.md#155-withdrawal)); in `terminal`
   mode check status/balance/lock before the PIN (L2).
6. **QR:** render `qr_payload` yourself; send scans to
   `GET /teller/customers/lookup?identifier=` URL-encoded.
7. **Errors:** `422` with `errors` = field validation; `422` without = business rule
   (show `message`); special extra fields for cash mismatch and PIN errors.
8. **Formatting:** integer Rupiah; timestamps are UTC, display in WIB; dates are
   Asia/Jakarta.
9. **PDFs:** request as blob; on a JSON response, show the error message.
10. Do not build features listed as not implemented
    ([FRONTEND_HANDOFF.md §20](FRONTEND_HANDOFF.md#20-not-implemented-do-not-build-ui-for-these)).

## Environment requirements

| Item | Requirement |
| --- | --- |
| API base URL | `{APP_URL}/api`. Local: `http://localhost:8000/api` (`php artisan serve`). For phones on the LAN: `php artisan serve --host=0.0.0.0 --port=8000` and the laptop's LAN IP; Android emulator `http://10.0.2.2:8000/api`. Production/demo URL: **UNKNOWN — must be configured by the deployment environment.** Frontends should read it from env (`VITE_API_BASE_URL`, `EXPO_PUBLIC_API_BASE_URL`) |
| CORS | Framework default (no `config/cors.php`): paths `api/*`, all origins, methods and headers, no credentials, no exposed headers. Works for bearer tokens. Restricting origins for production: **UNKNOWN — must be configured by the deployment environment** (publishing `config/cors.php` would be a backend change) |
| Database | MariaDB with InnoDB, a database and user configured in `.env` (`DB_CONNECTION=mariadb`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Run `php artisan migrate:fresh --seed` once (existing migrations were edited during development, so older databases must be rebuilt; this deletes their data). Production database host/credentials: **UNKNOWN — must be configured by the deployment environment** |
| Timezone | `APP_TIMEZONE=Asia/Jakarta` (default in `config/app.php`) |
| Cache | `CACHE_STORE=database` (uses the `cache` table from the migrations); the login rate limiter depends on it |
| Queue / sessions | Not used by the API (`QUEUE_CONNECTION=database`, `SESSION_DRIVER=database` defaults are harmless) |
| Filesystem / storage | No files are stored by the application. `storage/` must be writable (logs, framework cache, dompdf font cache in `storage/fonts`); the PHP temp directory must be writable (spreadsheet upload, dompdf temp). No `storage:link` needed |
| Scheduler | Recommended: `php artisan schedule:work` (development) or `schedule:run` from cron. Prunes expired tokens daily and runs `bank:expire-withdrawal-authorizations` every minute. Expiry is also applied on every read, so the API stays correct without it; the sweep only cleans abandoned rows |
| Firebase | `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS` (path to the service account JSON, server only), `FIREBASE_ALLOWED_PROVIDERS`; Firebase Console setup in [CUSTOMER_AUTH.md §6](CUSTOMER_AUTH.md#6-firebase-console-configuration). Production project and key: **UNKNOWN — must be created by the deployment owner** |
| Mail | `MAIL_*` SMTP settings ([CUSTOMER_AUTH.md §7](CUSTOMER_AUTH.md#7-smtp-configuration)); local `.env` uses `MAIL_MAILER=log`. Production SMTP account: **UNKNOWN** |
| Customer activation | `CUSTOMER_ACTIVATION_URL` (activation page of the customer app; placeholder until the app exists), `CUSTOMER_ACTIVATION_TOKEN_HOURS`, `CUSTOMER_SESSION_MINUTES` |
| Bank settings | `BANK_NAME`, `BANK_SCHOOL` (printed on PDFs and emails), `BANK_MIN_BALANCE` (default for the setting), `BANK_PIN_MAX_ATTEMPTS`, `BANK_PIN_LOCK_MINUTES`, `BANK_WITHDRAWAL_AUTH_PENDING_SECONDS`, `BANK_WITHDRAWAL_AUTH_APPROVED_SECONDS`, `SANCTUM_EXPIRATION` (minutes) — all in `.env.example` |
| Debug | Local `.env` has `APP_DEBUG=true`, so `500` bodies include stack traces; set `APP_DEBUG=false` outside development |
| Demo accounts | `admin` / `supervisor` / `teller` with password `password`; customers NIS `20260001`–`20260003`, PIN `123456`, emails `*@example.test`, activation pending (from the seeder; no invitation is sent). Customer app sign-in needs a Firebase project or the Auth emulator ([CUSTOMER_AUTH.md §9](CUSTOMER_AUTH.md#9-local-development-without-a-firebase-project)). The seeded `withdrawal_verification` is the default `mobile`, so withdrawals need the mobile app unless an admin switches to `terminal`/`both` |

## Backend invariants

Rules the frontend must never violate or work around:

1. **Authorization is enforced by the backend.** Hiding menus is only for usability;
   never assume a hidden page is protected, and never send requests for actions the
   role cannot perform expecting them to work.
2. **Transaction results come from the API.** Show the transaction (`id`,
   `balance_after`, `amount_in_words`) returned by the server; never construct a receipt
   or success state from the form values.
3. **The frontend never calculates an authoritative balance.** Customer balances, shift
   totals and system balances are always read from the API. Local arithmetic (e.g. a
   "remaining after withdrawal" preview) is a hint only.
4. **Never bypass server validation.** Client-side checks (multiples of 100, minimum
   balance, PIN format) are additions, not replacements.
5. **A request is not successful until the server responds with 2xx.** No optimistic
   updates for money, shift, review or account-status operations.
6. **Never retry a money request with a new idempotency key.** Retries reuse the key of
   the original submission.
7. **Never edit or delete transactions.** Use the correction endpoint.
8. **Never store PINs or passwords.** Only the token is stored. Customer passwords
   belong to Firebase; the Firebase service account key never goes into a client.
9. **Never trust `customer_id`-style parameters for customer data.** The customer API is
   scoped by token; do not try to read other customers' data.
10. **Dates and money:** send integer Rupiah and `YYYY-MM-DD` dates; do not send
    formatted strings.

## Repository and environment items outside the API

Not blocking frontend work, but not finished:

| Item | State |
| --- | --- |
| Mobile approval work | Committed as `2cc6b58` |
| Firebase customer auth | Committed on top of `4610046` |
| Developer database | Run `php artisan migrate` to add the customer email/Firebase columns and `customer_activation_tokens` (existing rows are kept; customers become pending). Older databases from before the earlier migration edits still need `migrate:fresh --seed` (destroys their data) |
| Dependencies | `composer audit` reports 13 advisories, none in the packages added for Firebase: `laravel/framework` 13.24.0 (3, including a critical `TokenGuard` bypass fixed in 13.29.0; this app's guards are `session` and `sanctum`, which do not use `TokenGuard`, but upgrading is recommended), `league/commonmark` (6), `league/flysystem`, `symfony/http-foundation`, `symfony/mime`, `symfony/yaml`. Updating within the existing constraints was not done |
| Composer script | The `post-update-cmd` script `boost:update` fails because Laravel Boost was never installed (`php artisan boost:install`); run Composer with `--no-scripts` or fix the script |
| `.env.example` | Defaults to `DB_CONNECTION=sqlite`; the README instructs MariaDB |
