# Documentation index

Documentation for the E-Teller Bank Mini Laravel API, written for frontend
developers and coding agents building the staff web app (React) and the customer
mobile app (React Native + Expo).

**The backend code is the source of truth.** All documents below were written from
the implementation (routes, middleware, controllers, form requests, services, API
resources, migrations, tests) at commit `5ddbd69`, updated for the mobile
withdrawal approval after `c405986` and for the Firebase customer login and activation
after `4610046`. If a document disagrees with the
code, the code wins; report the difference so the document can be fixed.

## Recommended reading order

| # | Document | Read it for |
| --- | --- | --- |
| 1 | [FRONTEND_HANDOFF.md](FRONTEND_HANDOFF.md) | **Start here.** How both clients use the API: base URL, auth flow, feature-to-endpoint map, request/response shapes, validation, errors, loading-sensitive operations, key workflows (user action → endpoint → validation → response → UI), known limitations and inconsistencies |
| 2 | [RBAC.md](RBAC.md) | Roles, middleware, ownership checks, the capability matrix, and every route's restrictions. Authentication vs authorization vs UI visibility |
| 3 | [WORKFLOWS.md](WORKFLOWS.md) | What the backend does in each business workflow: preconditions, validation, database effect, responses, failure cases, and what is not implemented |
| 4 | [AUTH.md](AUTH.md) | Login gateway, Sanctum tokens, expiry (no refresh), logout, current user, unauthorized responses, token storage on web and Expo |
| 4a | [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md) | Customer activation and Firebase login (Google, email/password), PIN unlock, invitation emails, Firebase Console and SMTP setup, environment variables, migration, security |
| 5 | [DATA_MODEL.md](DATA_MODEL.md) | Entities and fields as the API exposes them, enums and statuses, account number format, transaction types |
| 6 | [API.md](API.md) | Endpoint-by-endpoint reference with real captured request/response examples |
| 7 | [UI_REQUIREMENTS.md](UI_REQUIREMENTS.md) | Functional pages per role (Admin, Supervisor, Teller, Mobile User) that the API can support, with data, endpoints, states and actions |

Also available:

| Document | Purpose |
| --- | --- |
| [BACKEND_CHECKPOINT.md](BACKEND_CHECKPOINT.md) | Handoff status: technology versions, endpoint counts, limitations, environment requirements, invariants the frontend must respect |
| [PIN_VERIFICATION.md](PIN_VERIFICATION.md) | How customers authorize withdrawals: approval with PIN in the mobile app (default) or PIN at the teller terminal; the shared PIN lock; concurrency and security design |
| [API_SUMMARY.json](API_SUMMARY.json) | Machine-readable index of all 74 endpoints (method, path, auth, roles, request fields, validation, response, errors), generated from the route list. For coding agents; does not replace API.md |

## Which document is authoritative for what

| Question | Document |
| --- | --- |
| Exact endpoint, parameters, response example | [API.md](API.md) |
| Who may call an endpoint | [RBAC.md](RBAC.md) |
| What happens on the server, failure cases | [WORKFLOWS.md](WORKFLOWS.md) |
| How to authenticate and store tokens | [AUTH.md](AUTH.md) |
| Customer activation, Firebase, SMTP setup | [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md) |
| Firebase project and web config for the customer app | [firebase-web-config.md](firebase-web-config.md) |
| Production deployment (VPS, GitHub Actions CI/CD) | [DEPLOY.md](DEPLOY.md) |
| Field meanings and enums | [DATA_MODEL.md](DATA_MODEL.md) |
| What to build in the UI | [UI_REQUIREMENTS.md](UI_REQUIREMENTS.md) |
| Gaps, quirks, limitations | [FRONTEND_HANDOFF.md §18–20](FRONTEND_HANDOFF.md#18-known-backend-limitations) |
| Withdrawal PIN / mobile approval design | [PIN_VERIFICATION.md](PIN_VERIFICATION.md) |

## How these documents were produced

All documents are derived from the backend implementation:

- routes and middleware from `php artisan route:list` (74 API routes);
- validation from `app/Http/Requests` and inline `validate()` calls;
- behavior from `app/Services` and the controllers;
- response shapes from `app/Http/Resources`;
- fields from the migrations and models;
- examples in `API.md` from real requests against a seeded database;
- `API_SUMMARY.json` generated from `php artisan route:list --json` (paths, methods,
  auth, roles, rate limits) plus metadata checked against the form requests;
- two quirks (odd amounts blocking shift close, stale values on rejected shifts) were
  confirmed by running the code.

The test suite (`php artisan test`, 624 tests) covers the access matrix, business
rules and database constraints and is the best executable specification.

## Other project documents

- [../README.md](../README.md): backend installation, demo accounts, running tests.
- [../PRD.md](../PRD.md): original product requirements.
- [../RENCANA_PENGEMBANGAN.md](../RENCANA_PENGEMBANGAN.md): development plan and checklist.
