# Customer PIN Verification Audit

Audit of how the backend verifies a customer's PIN for teller transactions, and what
would be needed for two verification modes:

- **Mode A — mobile verification.** The teller starts a withdrawal; the customer
  approves it in the Expo app by entering their PIN; the teller then completes it. The
  teller never handles the PIN.
- **Mode B — teller-terminal fallback.** The customer has no phone; the PIN is typed on
  the teller terminal; the backend verifies it; the transaction completes.

Audited at commit `8d7f0e7`. **No backend code was changed.** Everything below
"Current implementation" is a proposal.

---

## 1. Answers

| # | Question | Answer |
| --- | --- | --- |
| 1 | Where is PIN verification implemented? | `App\Services\PinService::verify()` (`app/Services/PinService.php`). It is called by `TransactionService::withdraw()` (withdrawals) and `PinService::change()` (customer PIN change). The customer login checks the PIN separately with `Hash::check()` in `Customer\AuthController::login()`, **without** the attempt counter. |
| 2 | Which endpoint performs it? | `POST /api/teller/withdrawals` (field `pin`) for transactions. Also `POST /api/customer/pin` (`current_pin`) and the login endpoints `POST /api/login` / `/api/customer/login`. **There is no standalone "verify PIN" endpoint.** |
| 3 | Which role may call it? | Withdrawals: **teller** only (`auth:sanctum`, `staff:teller`). PIN change: the **customer** (`auth:sanctum`, `customer`). Login: public (rate limited). |
| 4 | Does the transaction endpoint require customer authentication? | **No.** The teller's token authenticates the request; the customer is identified by `account_number` and authorizes only by the PIN value in the body. No customer token or session is involved. |
| 5 | Can a teller currently verify a customer's PIN? | **Yes, implicitly**: by submitting a withdrawal with the PIN. Verification and execution happen in the same request; there is no "check PIN only" call. |
| 6 | Can a teller verify a PIN without receiving it? | **No.** The PIN must travel from the teller client to the API. The teller's browser always handles the PIN (ideally typed by the customer on a customer-facing PIN pad). |
| 7 | Can a mobile customer authorize a teller-initiated transaction? | **No.** The customer API is read-only, plus PIN change. There is nothing to approve. |
| 8 | Is there a transaction authorization/challenge mechanism? | **No.** No table, model, status or endpoint for pending authorizations. The only "authorization" records are supervisor-authorized corrections (`transactions.authorized_by`), which is a different feature. |
| 9 | Can the mobile app and teller session be securely associated with one transaction? | **Not today.** Both can refer to the same customer (`customers.id` / `account_number`), but no server-side object binds a teller request, a customer approval and one transaction together. |
| 10 | Is there a suitable transaction ID or UUID? | **No.** `transactions.id` is an auto-increment integer created only when money moves (too late for a challenge, and guessable). `idempotency_key` is client-chosen and per teller, so it's unsuitable as a shared secret. The only UUID column in the database belongs to Laravel's `failed_jobs` table. |
| 11 | Is there polling or websocket infrastructure? | **No websockets or push.** No broadcasting config, no `routes/channels.php`, no Reverb/Pusher/Echo/Expo-push packages, no events, `BROADCAST_CONNECTION=log`. No dedicated polling endpoints either. Ordinary `GET` endpoints can be polled, and Sanctum bearer auth works for repeated requests. |
| 12 | Backend changes for Mode A? | New authorization table, model, service, teller and customer endpoints, completion by authorization id, expiry, tests (see §5). |
| 13 | Backend changes for Mode B? | **None required**: it works today through `POST /teller/withdrawals`. Optional: record the verification method, fix the check order (L2), and put customer login under the same PIN lock (see §6). |
| 14 | Which mode is supported? | **Mode B** (single-step: verification and withdrawal in one request). |
| 15 | Which mode needs new endpoints/database changes? | **Mode A.** |

---

## 2. Current implementation

### PIN storage and hashing
- Column `customers.pin` (string), cast `'hashed'` on the `Customer` model: any value
  assigned is hashed with **bcrypt** (`BCRYPT_ROUNDS=12`). Plaintext is never stored.
- `pin` is in `$hidden`, and no API resource includes it. Validation errors list
  messages, not submitted values. With `APP_DEBUG=true`, JSON error responses include
  a trace, but Laravel strips function arguments from it and never includes request
  input. **The PIN is never returned by the API.**
- PINs are set on account creation (`POST /admin/customers`), by an admin reset
  (`PATCH /admin/customers/{id}`, which also clears the lock), and by the customer
  (`POST /customer/pin`).
- No application code writes request bodies to logs.

### Attempt tracking and lockout (`PinService::verify`)
- Runs in **its own database transaction** with a row lock on the customer, so a failed
  attempt is saved even though the withdrawal request fails.
- `pin_failed_attempts` (consecutive wrong PINs) and `pin_locked_until` on `customers`.
- 3 wrong in a row (`BANK_PIN_MAX_ATTEMPTS`) → locked for 15 minutes
  (`BANK_PIN_LOCK_MINUTES`). While locked, even the correct PIN is refused.
- A correct PIN resets the counter.
- Error: `422 {"message": "...", "remaining_attempts": n, "pin_locked_until": <UTC|null>}`.
- Shared by withdrawals and customer PIN change. **Not used by customer login**, which
  only has the rate limit of 5 attempts per minute per identifier + IP.

### Withdrawal flow (`POST /api/teller/withdrawals`)
1. Middleware: teller token, active teller.
2. Validation: `account_number` exists and is not closed, `amount` 1–100,000,000, `pin`
   exactly 6 digits, optional `Idempotency-Key` header.
3. `TransactionService::withdraw()`:
   1. If an `Idempotency-Key` matches an earlier withdrawal by this teller for the same
      customer and amount, that transaction is returned (200) **without re-checking the
      PIN**.
   2. `PinService::verify()` (separate DB transaction; counts attempts).
   3. Main DB transaction: lock teller → lock active shift dated today → lock customer →
      status `active` → `balance − amount ≥ min_balance` → update balance → insert
      transaction → insert two journal lines.
4. `201` Transaction, or `422` / `403` / `500` with nothing saved (apart from the PIN
   counter).

### Deposit flow (`POST /api/teller/deposits`)
Same as a withdrawal **without any customer verification**: no PIN, no customer
confirmation. That matches the UKK soal, which requires the PIN only for withdrawals.

### Transaction identity
- Auto-increment `transactions.id`, created when money moves.
- `idempotency_key` (client-chosen, unique per teller) prevents double recording.
- Transactions are immutable. A mistake is reversed by a supervisor-authorized
  correction.

### Infrastructure relevant to Mode A
| Item | State |
| --- | --- |
| Auth | Sanctum bearer tokens, 480 min, no abilities/scopes on tokens |
| Customer middleware | `customer` actor middleware; all customer endpoints are scoped to the token's customer |
| Rate limiting | `throttle:login` (5/min per identifier + IP), `throttle:10,1` on corrections; none on customer endpoints |
| CORS | Framework default: all origins, no credentials, no exposed headers (the mobile app is not affected) |
| Broadcasting / websockets | Not installed. `BROADCAST_CONNECTION=log` |
| Push notifications | Not installed (no device tokens, no Expo push) |
| Queues / scheduler | Database queue configured but unused; scheduler only prunes expired tokens |

---

## 3. Current limitations

1. **The teller client always handles the PIN** (Mode B by design). Mode A is impossible today.
2. **No separate verify step.** "Verify, then complete" is one request. That's actually
   safer (nothing reusable exists between the two steps), but a UI can't show "PIN
   verified" before the money moves.
3. **Check order (L2).** The PIN is verified before the shift, status and balance checks,
   so a wrong PIN counts even when the withdrawal would fail anyway, and a correct PIN
   resets the counter even if the withdrawal then fails.
4. **The verification method is not recorded.** Nothing says how a withdrawal was
   authorized (PIN at the terminal); there is no audit field for it.
5. **Customer login bypasses the PIN lock.** The login uses the same 6-digit PIN but only
   has a per-identifier+IP rate limit. An attacker who knows a NIS and uses many IPs
   isn't stopped by the 3-attempt lock. A customer token is exactly what Mode A would
   use to approve withdrawals, so this matters more once Mode A exists.
6. **Customer tokens are all-powerful within the customer scope** (no abilities). Any
   logged-in device of the customer could approve a Mode A challenge.
7. **No realtime channel** to tell the customer's phone that a challenge is waiting, or
   to tell the teller it was approved.
8. **Transport security is a deployment matter.** PINs are only protected in transit if
   the API is served over HTTPS (deployment URL unknown).

---

## 4. Supported flow today (Mode B, single-step)

```text
Teller looks up customer          GET  /api/teller/customers/lookup?identifier=...
Teller enters amount
Customer types PIN on the teller terminal (masked, customer-facing pad)
Teller confirms                   POST /api/teller/withdrawals
                                  {account_number, amount, pin} + Idempotency-Key
Backend: verify PIN (counts attempts) → shift → status → min balance → record + journal
Result                            201 Transaction | 422 wrong PIN (remaining_attempts) |
                                  422 locked (pin_locked_until) | 422 balance/shift/status
```

What already holds:
- No PIN storage or exposure.
- Brute-force lock per customer.
- Nothing reusable is issued, so there's no authorization to replay.
- Idempotent retries return the same transaction.

---

## 5. Proposed mobile verification flow (Mode A)

Not implemented. A design that fits the existing architecture (services, Sanctum,
row locks, polling).

### 5.1 Concept: a withdrawal authorization ("challenge")

A server-side record created by the teller **before** money moves, bound to one teller,
one customer, one amount and one shift, approved by the customer with their PIN in the
app, and **consumed exactly once** when the teller completes the withdrawal.

Proposed table `withdrawal_authorizations`:

| Column | Purpose |
| --- | --- |
| `id` | Internal key |
| `public_id` (UUID/ULID, unique) | Identifier used in URLs; unguessable. Access is still enforced by token ownership, not by secrecy |
| `teller_id` → users, `customer_id` → customers, `daily_report_id` → daily_reports | Binding (ON DELETE RESTRICT) |
| `amount` | Bound amount (CHECK > 0) |
| `method` | `mobile` (Mode A) or `terminal` (if Mode B is also recorded here) |
| `status` | `pending` → `approved` → `consumed`; or `rejected` / `cancelled` / `expired` |
| `expires_at` | Pending lifetime (e.g. 2 minutes) |
| `approved_at`, `approval_expires_at` | Approval valid for a short window (e.g. 2 minutes) |
| `consumed_at`, `transaction_id` → transactions (unique, nullable) | Single use, audit link |
| `rejected_at`, `cancelled_at` | Audit |

### 5.2 Proposed endpoints

| Actor | Endpoint | Behavior |
| --- | --- | --- |
| Teller | `POST /api/teller/withdrawal-authorizations` `{account_number, amount}` | Validates shift (open, today), account `active`, PIN not locked, `balance − amount ≥ min_balance` **before** bothering the customer. Refuses if the customer already has a pending challenge. Returns `201 {id, status: pending, expires_at, customer summary}`. Moves no money |
| Teller | `GET /api/teller/withdrawal-authorizations/{id}` | Status for polling (own challenges only; expiry applied lazily on read) |
| Teller | `POST /api/teller/withdrawal-authorizations/{id}/cancel` | Cancels a pending/approved challenge |
| Customer | `GET /api/customer/withdrawal-authorizations` | Pending challenges for **own** account only: amount, teller name, time, expiry |
| Customer | `POST /api/customer/withdrawal-authorizations/{id}/approve` `{pin}` | `PinService::verify()` (same lock counter) → `approved`. Wrong PIN → `422` with `remaining_attempts`; lock → `422` |
| Customer | `POST /api/customer/withdrawal-authorizations/{id}/reject` | `rejected` |
| Teller | `POST /api/teller/withdrawals` `{authorization_id}` (instead of `account_number`/`amount`/`pin`) | In one DB transaction: lock the challenge; require `approved`, not expired, `teller_id` = caller, same shift; take account and amount **from the challenge** (not from the request); run the normal withdrawal checks; insert the transaction; set `consumed`, `consumed_at`, `transaction_id`. Keeps `Idempotency-Key` support |

### 5.3 Sequence

```text
Teller                         Backend                              Customer (Expo)
  │ POST authorizations ─────────▶ checks shift/status/balance/lock
  │ ◀── 201 {id, pending, expires_at}
  │ poll GET authorizations/{id}                         poll GET /customer/withdrawal-authorizations
  │                               ◀──────────────────────────────── (or push, if added)
  │                                                     shows amount + teller → enters PIN
  │                               ◀── POST .../{id}/approve {pin} ─
  │                               PinService::verify → approved
  │ ◀── status approved
  │ POST /teller/withdrawals {authorization_id} ──▶ consume + record + journal (one DB tx)
  │ ◀── 201 Transaction (receipt)
```

### 5.4 Delivery to the phone and back to the teller

| Option | Backend work | Notes |
| --- | --- | --- |
| **Polling (recommended for the UKK scope)** | Only the endpoints above | Teller polls every 1–2 s while waiting; the app polls the pending list while open (or the customer taps "Refresh"). Works with today's stack; no new server process |
| Laravel Reverb (websockets) | New package, broadcasting config, channel authorization for both actors, a separate long-running server process, events | Instant updates; more moving parts and deployment work |
| Expo push notifications | Device token table and endpoint, Expo push sending (queue), handling revoked tokens | Alerts the customer when the app is closed; needs a decision on how to handle phones without internet |

---

## 6. Proposed teller-terminal fallback (Mode B)

**Works today without changes** (§4). Optional improvements, each a backend change:

1. **Record the method.** Store how each withdrawal was authorized (`terminal` vs
   `mobile`): either a column on `transactions` or a `withdrawal_authorizations` row with
   `method = terminal` consumed in the same request.
2. **Fix the check order (L2).** Verify the PIN only after the shift, status and balance
   checks pass. (A balance or status failure would then no longer cost a PIN attempt.)
3. **Put customer login under the PIN lock** so the same 3-attempt limit protects every
   place the PIN is entered.
4. **Policy switch (optional).** If Mode A becomes the default, a setting could allow
   terminal PIN entry only as a fallback (e.g. a `withdrawal_verification` setting:
   `terminal`, `mobile`, `both`). That's a product decision.

Keeping Mode B **single-step** is recommended: a separate "verify PIN" endpoint for the
terminal would create a reusable proof that has to be bound, expired and consumed like
a Mode A challenge.

---

## 7. Required backend changes

| Change | Mode A | Mode B |
| --- | :-: | :-: |
| Migration + model `withdrawal_authorizations` (UUID, binding, status, expiry, consumption, unique `transaction_id`) | Required | Optional (method audit) |
| `WithdrawalAuthorizationService` (create, approve with `PinService`, reject, cancel, lazy expiry, consume inside `TransactionService`) | Required | — |
| Teller endpoints: create, show (poll), cancel | Required | — |
| Customer endpoints: list pending, approve `{pin}`, reject | Required | — |
| `POST /teller/withdrawals` accepts `authorization_id` as an alternative to `pin` (mutually exclusive; values taken from the challenge) | Required | — |
| One pending challenge per customer; rate limit on approve | Required | — |
| Customer login under the PIN lock counter | Strongly recommended | Recommended |
| Verify the PIN after the shift/status/balance checks (L2) | Built into the challenge creation pre-checks | Recommended |
| Record the authorization method on withdrawals | Required for audit | Recommended |
| Optional setting for allowed verification modes | Decision | Decision |
| Reverb / Expo push | Optional (decision) | — |
| Tests: binding, replay, expiry, ownership, lock, consumption, cross-teller, cross-customer | Required | For any change made |
| Documentation updates (API.md, API_SUMMARY.json, RBAC, workflows) | Required | For any change made |

## 8. Required frontend changes

### Staff web app (teller)
- **Mode B (works now):** customer-facing masked PIN pad on the confirmation screen;
  numeric only, cleared after each submit, never stored, logged or put in URLs; handle
  `remaining_attempts` / `pin_locked_until`; pre-check status, balance and lock before
  asking (L2); `Idempotency-Key` per submission.
- **Mode A (after backend work):** "Verify on phone" option → create challenge → waiting
  screen with countdown and cancel → poll status → on `approved`, "Complete
  withdrawal" (`authorization_id`) → receipt. Handle `rejected` / `expired` /
  `cancelled`, and offer the Mode B fallback if allowed.

### Expo app (customer)
- **Mode B:** nothing.
- **Mode A:** a pending-approvals screen (polling or push) showing amount, teller name,
  time and expiry; PIN entry (masked, numeric, secure text entry); approve and reject;
  handle wrong PIN and lock; optionally require device re-authentication (e.g. biometric
  through `expo-local-authentication`) before showing the PIN pad.

## 9. Security considerations

| Requirement | Today (Mode B) | Proposed Mode A |
| --- | --- | --- |
| Never return plaintext PINs | ✅ hidden attribute, no resource field, no input echo, no trace args | Same; approve responses must not echo the PIN |
| Never store plaintext PINs | ✅ bcrypt (12 rounds) via the `hashed` cast | Same; the challenge table stores no PIN |
| Never expose PINs in responses | ✅ | ✅ |
| Avoid sending the PIN to teller clients | ❌ inherent in Mode B; mitigate with a customer-facing pad and HTTPS | ✅ the PIN goes only from the customer's phone to the API |
| Prevent replay of authorization | ✅ nothing reusable is issued; idempotent replays return the **same** transaction | Single-use `consumed` status + unique `transaction_id`, set in the same DB transaction as the withdrawal, under a row lock |
| Prevent authorizing a different transaction | ✅ PIN and transaction travel in one request | Completion takes account and amount **from the challenge**; challenge bound to teller, customer, shift; the customer sees amount and teller before approving |
| Expire pending authorizations | n/a | `expires_at` for pending, short validity after approval; enforced on every read/approve/consume (no scheduler needed); optional scheduled cleanup |
| Prevent brute force | ✅ 3 wrong → 15-minute lock per customer, persisted even on failure. ⚠️ customer login not covered | Approve uses the same `PinService` lock; add a rate limit on approve; put login under the lock |
| Teller cannot authorize without the customer | ✅ requires the PIN (knowledge factor) | ✅ approve is reachable only with a **customer** token for that challenge's customer; tellers cannot call customer endpoints (`customer` middleware) |
| Authorization not reusable | ✅ none exists | One challenge → at most one transaction (DB unique + status guard) |
| Association of the two sessions | n/a | Bound server-side; customer sees only own challenges, teller only own; IDs unguessable; one pending challenge per customer avoids mix-ups |
| Transport | ⚠️ HTTPS depends on deployment | Same |

## 10. Open decisions

1. **Which mode is default?** Mode A only, Mode B only (today), or both with Mode B as an
   explicit fallback? Should the fallback be recorded or require a supervisor?
2. **Scope:** withdrawals only (as the soal requires), or also deposits or corrections?
3. **Who completes in Mode A:** the teller presses "Complete" after approval (the flow in
   the request), or approval executes the withdrawal immediately?
4. **Delivery:** polling only, Reverb websockets, or Expo push notifications?
5. **Timeouts:** pending lifetime and approval validity (proposal: 2 minutes each).
6. **One pending challenge per customer** (proposal: yes) and what happens to a pending
   challenge when the teller's shift closes (proposal: expire/cancel).
7. **PIN lock policy:** share the 3-attempt lock between login, approval, withdrawal and
   PIN change? (Recommended.)
8. **Extra customer-side check** before showing the approval PIN pad (device biometric)?
9. **Should the L2 order fix and method auditing be done** even if Mode A is not built?
10. **UKK scope:** Mode A goes beyond the soal (which describes PIN entry "pada layar
    konfirmasi saat di depan loket Teller", i.e. Mode B). Is it worth the extra scope?
