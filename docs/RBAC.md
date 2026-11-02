# Authorization (RBAC)

The actual authorization model of the backend, taken from `routes/api.php`,
`app/Http/Middleware`, the controllers and the services. There are **no Laravel
policies or gates** in this project; authorization is done by route middleware,
a few ownership checks in controllers, and business rules in services.

## 1. Authentication vs authorization vs UI visibility

| Layer | Question | Where | Failure |
| --- | --- | --- | --- |
| **Authentication** | Who is calling? | `auth:sanctum` on every route except login and customer activation | `401 Unauthenticated.` |
| **Authorization** | Is this actor/role allowed to call this endpoint? Is this record theirs? | `staff`, `staff:{roles}`, `customer` middleware; controller ownership checks | `403` (role) or `404` (not theirs) |
| **Business rules** | Is the action allowed in the current state? | Services (shift state, balance, PIN, report status, ...) | `422` |
| **Frontend visibility** | Which menus and buttons to show | React / Expo code | — |

**Hiding a page or button in the frontend is not security.** Every request is
re-checked by the backend against the database (role and `is_active` are read
fresh for each token). The backend is authoritative; the frontend only mirrors it
for usability.

## 2. Actors and roles

| Actor | Model | Role value | Notes |
| --- | --- | --- | --- |
| Administrator | `App\Models\User` | `admin` | Created by the seeder; cannot be created, edited, blocked or deleted through the API |
| Supervisor | `User` | `supervisor` | |
| Teller | `User` | `teller` | Usually a student on duty (`class`, `phone` fields) |
| Customer ("User" in the matrix below; nasabah) | `App\Models\Customer` | — (separate model) | Students; sign in with Firebase (Google or email/password), activate once with their NIS, then create their own PIN, which unlocks the app and approves withdrawals ([CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)) |

A staff member has exactly one role (`users.role`). Multi-role users are not
implemented.

## 3. Middleware

| Middleware | Class | Effect |
| --- | --- | --- |
| `auth:sanctum` | Laravel Sanctum | Requires a valid, unexpired bearer token. Otherwise `401 {"message":"Unauthenticated."}` |
| `staff` | `EnsureStaffRole` | Token must belong to a `User`, and `is_active` must be true. Otherwise `403 "Fitur ini khusus untuk staf bank."` / `"Akun staf ini sudah dinonaktifkan."` |
| `staff:admin,supervisor` (etc.) | `EnsureStaffRole` | As above, plus `role` must be in the list. Otherwise `403 "Peran Anda tidak memiliki akses ke fitur ini."` |
| `customer` | `EnsureCustomer` | Token must belong to a `Customer` whose status is not `inactive`. Otherwise `403 "Fitur ini khusus untuk nasabah."` / `"Rekening ini sudah tidak aktif."` |
| `throttle:login` | Rate limiter | 5 attempts per minute per username/identifier + IP on login routes; requests with only `id_token`: 30 per minute per IP (`429`) |
| `throttle:customer-activation`, `customer-unlock` | Rate limiters | Activation 20/min per IP; unlock and PIN setup 5/min per customer (`429`) |
| `throttle:10,1` | Rate limiter | 10 corrections per minute per teller (`429`) |

The role middleware runs **before** route model binding, so a caller with the
wrong role always gets `403` and cannot learn from `404` vs `200` whether a record
id exists. This is covered by `tests/Feature/RoleAccessTest.php`, which checks
every listed endpoint for every actor, and verifies that every API route except
the login routes and the three public `/customer/activation/*` routes has both
`auth:sanctum` and an actor middleware.

## 4. Controller-level checks

| Check | Endpoint(s) | Result |
| --- | --- | --- |
| Teller may only see/print their own shifts | `GET /teller/daily-reports/{id}`, `GET /teller/daily-reports/{id}/export` | `404` for other tellers' reports |
| Teller may only print/correct their own transactions | `GET /teller/transactions/{id}/receipt`, `POST /teller/transactions/{id}/correct` | `404` |
| Lists are scoped to the caller | `GET /teller/transactions`, `GET /teller/daily-reports`, `GET /teller/shift`, all `/customer/*` | Only own data; request parameters cannot select another account |
| Customer transaction detail | `GET /customer/transactions/{id}` | `404` for other customers' transactions |
| Withdrawal requests (teller side) | `GET/POST /teller/withdrawal-authorizations/{id}[/cancel\|/complete]` | `404` for requests created by another teller |
| Withdrawal requests (customer side) | `GET/POST /customer/withdrawal-authorizations/{id}[/approve\|/reject]` | `404` for requests for another customer; nothing about other customers is revealed |
| Administrator accounts are protected | `PATCH/PUT/DELETE /admin/staff/{id}`, `POST .../block`, `.../unblock` | `403 "Akun administrator tidak dapat diubah melalui API."` |
| Correction authorization | `POST /teller/transactions/{id}/correct` | Requires `supervisor_username` + `supervisor_password` of an **active supervisor** in the body (`422` otherwise). The supervisor is recorded in `authorized_by` |

## 5. Capability matrix

Only capabilities that exist in the backend. "User" = customer (nasabah).
✓ = allowed. — = refused by the backend (`403`).

| Capability | Admin | Supervisor | Teller | User |
| --- | :-: | :-: | :-: | :-: |
| Log in / log out / read own profile | ✓ | ✓ | ✓ | ✓ |
| Read majors and settings | ✓ | ✓ | ✓ | — |
| Change settings (minimum balance, scanner mode, withdrawal verification mode) | ✓ | — | — | — |
| List / view staff | ✓ | ✓ | — | — |
| Create, edit, block, unblock, delete, import tellers/supervisors | ✓ | — | — | — |
| List / view customer accounts (incl. closed) | ✓ | ✓ | — | — |
| Open account, edit, reset (remove) PIN, suspend, graduate (deactivate); set the email of a pending account | ✓ | — | — | — |
| Activate own account (Firebase sign-in with the registered email + NIS) | — | — | — | ✓ (public, proof by verified email + NIS) |
| Create own PIN (after activation, with a fresh Firebase sign-in); unlock the app with it; log out of all own sessions | — | — | — | ✓ |
| Customer card data (QR payload) for printing | ✓ | — | — | — |
| Customer statement PDF (any customer) | ✓ | ✓ | — | — |
| Look up one customer by QR / account number / NIS | — | — | ✓ | — |
| Open / view / close own shift | — | — | ✓ | — |
| Deposit, withdrawal | — | — | ✓ | — |
| Start, poll, cancel and complete a withdrawal request (mobile approval) | — | — | ✓ | — |
| Approve (with own PIN) or reject a withdrawal request for own account | — | — | — | ✓ |
| Correction of own transaction | — | — (authorizes with credentials) | ✓ | — |
| Own transactions of a day, receipt PDF | — | — | ✓ | — |
| Own shift history, daily report PDF | — | — | ✓ | — |
| List / view all daily reports, report PDF | — | ✓ | — | — |
| Approve / hold / reject daily report | — | ✓ | — | — |
| Vault ledger (cash transfers) | — | ✓ | — | — |
| Audit transaction log (+ PDF) | ✓ | ✓ | — | — |
| Accounting journal, trial balance (+ PDF) | ✓ | ✓ | — | — |
| Annual report PDF | ✓ | ✓ | — | — |
| Own balance, statement, transaction detail, statement PDF | — | — | — | ✓ |
| Own digital card (QR payload) | — | — | — | ✓ |
| Change own PIN | — | — | — | ✓ |
| Change own staff password | — | — | — | — |

Notes:

- **Supervisor and corrections.** A supervisor never calls the correction endpoint.
  They authorize a teller's correction by typing their own username and password on
  the teller's screen.
- **Customer PIN.** By default (`withdrawal_verification = mobile`) the customer
  authorizes a withdrawal in the mobile app: the teller creates a request, the customer
  approves it with their PIN, the teller completes it. Staff can never call the approve
  endpoint (`customer` middleware) and never receive the PIN. The customer can never
  create a request or a transaction. In `terminal`/`both` mode the customer may instead
  type the PIN on the teller's screen (`POST /teller/withdrawals`).
- **Nobody** can edit or delete transactions, journal lines, approved reports or
  cash transfers (no endpoints, plus model guards).
- **Change own staff password** is not implemented for anyone; an admin can set a
  teller's or supervisor's password via `PATCH /admin/staff/{id}`.

## 6. What each role can and cannot do

### Administrator
- **Can:** manage tellers and supervisors (including spreadsheet import), manage
  customer accounts (open, edit, reset PIN, suspend, graduate), get card data,
  change settings, read the audit log, journal and trial balance, download the
  journal/audit/statement/annual PDFs, list staff and customers.
- **Cannot:** record or correct transactions, run shifts, look up customers at the
  counter, see or review daily reports, see the vault ledger, edit any admin account
  (including their own), manage majors.

### Supervisor
- **Can:** list/view staff and customers, review daily reports (approve, hold,
  reject), print daily reports, see the vault ledger, read the audit log, journal
  and trial balance, download PDFs (journal, audit, statement, annual), authorize
  corrections with their credentials.
- **Cannot:** create or edit staff or customers, change settings, record
  transactions, run shifts, print customer cards, bulk-approve.

### Teller
- **Can:** open and close their own shift, look up a customer (QR, account number,
  NIS), deposit, withdraw (after the customer approves in the app, or with the PIN at
  the terminal if enabled), correct their own transaction
  (with a supervisor's credentials), list their own transactions per day, print
  receipts, see their own shift history and print their own daily reports, read
  majors and settings.
- **Cannot:** browse the customer list, see other tellers' data, review reports,
  read the audit log or journal, change settings, manage staff or customers.

### Customer (User)
- **Can:** see own profile, balance (with the minimum balance), statement with
  filters, single transaction, digital card, download own statement PDF, change
  PIN, approve or reject withdrawal requests a teller made for their account, log out.
- **Cannot:** start a withdrawal or move money, see other customers, see staff data, journals or
  internal reports, use any staff endpoint.

## 7. Endpoint restrictions

All 72 API routes (`php artisan route:list --path=api`). Prefix `/api`.

| Method | Path | Middleware (beyond `api`) |
| --- | --- | --- |
| POST | `/login`, `/staff/login`, `/customer/auth/login` | `throttle:login` (public) |
| POST | `/customer/activation` | `throttle:customer-activation` (public; the Firebase verified email + NIS are the proof) |
| GET | `/staff/me` · POST `/staff/logout` | `auth:sanctum`, `staff` |
| GET | `/majors`, `/settings` | `auth:sanctum`, `staff` |
| GET | `/staff`, `/staff/{staff}` | `staff:admin,supervisor` |
| POST | `/admin/staff`, `/admin/staff/import` | `staff:admin` |
| PUT/PATCH, DELETE | `/admin/staff/{staff}` | `staff:admin` |
| POST | `/admin/staff/{staff}/block`, `/admin/staff/{staff}/unblock` | `staff:admin` |
| PUT/PATCH | `/admin/settings` | `staff:admin` |
| GET | `/customers`, `/customers/{customer}`, `/customers/{customer}/statement` | `staff:admin,supervisor` |
| POST | `/admin/customers` | `staff:admin` |
| PUT/PATCH | `/admin/customers/{customer}` | `staff:admin` |
| POST | `/admin/customers/{customer}/deactivate` | `staff:admin` |
| POST | `/admin/customers/{customer}/pin-reset` | `staff:admin` |
| GET | `/admin/customers/{customer}/card` | `staff:admin` |
| GET | `/audit/transactions`, `/audit/transactions/export`, `/audit/transactions/{transaction}` | `staff:admin,supervisor` |
| GET | `/audit/journals`, `/audit/journals/summary`, `/audit/journals/export` | `staff:admin,supervisor` |
| GET | `/reports/annual` | `staff:admin,supervisor` |
| GET | `/teller/customers/lookup` | `staff:teller` |
| GET | `/teller/shift` · POST `/teller/shift/open`, `/teller/shift/close` | `staff:teller` |
| GET | `/teller/daily-reports`, `/teller/daily-reports/{dailyReport}`, `/teller/daily-reports/{dailyReport}/export` | `staff:teller` + ownership |
| POST | `/teller/deposits`, `/teller/withdrawals` | `staff:teller` (`/withdrawals` also needs the `terminal`/`both` setting) |
| POST | `/teller/withdrawal-authorizations` | `staff:teller`, `throttle:withdrawal-authorization-create` |
| GET | `/teller/withdrawal-authorizations`, `/teller/withdrawal-authorizations/{withdrawalAuthorization}` | `staff:teller`, `throttle:withdrawal-authorization-poll` + ownership |
| POST | `/teller/withdrawal-authorizations/{withdrawalAuthorization}/cancel`, `/complete` | `staff:teller`, `throttle:withdrawal-authorization-poll` + ownership |
| GET | `/teller/transactions`, `/teller/transactions/{transaction}/receipt` | `staff:teller` + ownership |
| POST | `/teller/transactions/{transaction}/correct` | `staff:teller`, `throttle:10,1` + ownership + supervisor credentials |
| GET | `/supervisor/daily-reports`, `/supervisor/daily-reports/{dailyReport}`, `/supervisor/daily-reports/{dailyReport}/export` | `staff:supervisor` |
| POST | `/supervisor/daily-reports/{dailyReport}/approve`, `/hold`, `/reject` | `staff:supervisor` |
| GET | `/supervisor/cash-transfers`, `/supervisor/cash-transfers/{cashTransfer}` | `staff:supervisor` |
| GET | `/customer/me`, `/customer/balance`, `/customer/card`, `/customer/transactions`, `/customer/transactions/{transaction}`, `/customer/statement/export` | `auth:sanctum`, `customer` |
| POST | `/customer/logout`, `/customer/logout-all`, `/customer/pin` | `auth:sanctum`, `customer` |
| POST | `/customer/unlock`, `/customer/pin/setup` | `auth:sanctum`, `customer`, `throttle:customer-unlock` |
| GET | `/customer/withdrawal-authorizations`, `/customer/withdrawal-authorizations/{withdrawalAuthorization}` | `auth:sanctum`, `customer`, `throttle:withdrawal-authorization-poll` + ownership |
| POST | `/customer/withdrawal-authorizations/{withdrawalAuthorization}/approve`, `/reject` | `auth:sanctum`, `customer`, `throttle:withdrawal-authorization-decide` + ownership |

Every `staff:*` route also has `auth:sanctum` and `staff`.

## 8. Frontend routing guidance

- Route by `actor` from the login response, then by `user.role`.
- The web app should not offer customer login (refuse `actor: "customer"`), and the
  mobile app should refuse `actor: "staff"`.
- On `401` or `403` from a role check, clear the token and return to the login page
  (UKK rubric 3.2: lower-level users opening a supervisor URL are thrown back to the
  login page). The one `403` that is a normal business error is
  `Akun administrator tidak dapat diubah melalui API.` on staff edit.
- Client-side route guards should use the same matrix as §5 so users do not see
  pages that would fail, but they are a convenience only.
