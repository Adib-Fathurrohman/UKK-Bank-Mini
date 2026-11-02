# Customer PIN Verification

How the backend verifies a customer's PIN for withdrawals, in two modes:

- **Mode A — mobile approval (implemented, default).** The teller starts a withdrawal;
  the customer approves it in the Expo app with their PIN; the teller completes it. The
  teller never sees, types or sends the PIN.
- **Mode B — PIN at the teller terminal (existing, off by default).** The customer types
  the PIN on the teller terminal and the withdrawal runs in one request
  (`POST /api/teller/withdrawals`). Kept as a fallback that an administrator can switch on.

The first version of this document (commit `c405986`) was an audit made before Mode A
existed. This version describes what is implemented. §8 lists the decisions taken.

---

## 1. Summary

| Question | Answer |
| --- | --- |
| Where is the PIN checked? | Only in `App\Services\PinService::verify()`. Callers: the NIS + PIN check (`/customer/login`, `/customer/activation/resend`), the app unlock (`/customer/unlock`), withdrawal approval (Mode A), terminal withdrawal (Mode B), PIN change |
| Does the PIN start a session? | **No** (since the Firebase customer login, [CUSTOMER_AUTH.md](CUSTOMER_AUTH.md)). Sessions start from a Firebase sign-in; the PIN unlocks the app during the session and authorizes withdrawals |
| Who may submit a PIN? | **Only the customer** in Mode A (`POST /api/customer/withdrawal-authorizations/{id}/approve`), and only for their own request. In Mode B the teller's client sends it, which is why Mode B is off by default |
| Can the customer start a withdrawal? | **No.** Customers can only list, approve or reject requests a teller created. All transaction endpoints are teller-only |
| Can staff approve for the customer? | **No.** The approve/reject endpoints require a customer token (`customer` middleware); tellers, supervisors and administrators get `403` |
| Is the PIN stored or returned? | **No.** bcrypt hash in `customers.pin` (hidden attribute); no resource, error or log contains it. The authorization table has no PIN column |
| How does the teller learn about the approval? | **Polling** `GET /api/teller/withdrawal-authorizations/{id}`. No websockets or push |
| How is a double withdrawal prevented? | Row locks, a compare-and-set status change `approved → consumed` in the same DB transaction as the withdrawal, a unique `transaction_id`, and an idempotent `complete` |
| Which mode is active? | Setting `withdrawal_verification`: `mobile` (default), `terminal` or `both` |

---

## 2. PIN storage and the shared lock

- `customers.pin` is cast `hashed` (bcrypt, `BCRYPT_ROUNDS=12`) and is in `$hidden`.
- `PinService::verify()` runs in **its own DB transaction** with a row lock on the
  customer, so a failed attempt is saved even though the request fails. Callers that
  have a bigger DB transaction (approval, terminal withdrawal) call it **before** that
  transaction.
- `pin_failed_attempts` counts consecutive wrong PINs. The 3rd wrong PIN
  (`BANK_PIN_MAX_ATTEMPTS`) sets `pin_locked_until` to now + 15 minutes
  (`BANK_PIN_LOCK_MINUTES`). While locked, even the correct PIN is refused. A correct
  PIN resets the counter. An administrator PIN reset clears the lock.
- **One lock for every place the PIN is entered**: the NIS + PIN check, the app unlock,
  the invitation resend, Mode A approval, Mode B withdrawal and PIN change. Three wrong
  PINs at one place lock all the others too.

### NIS + PIN check (formerly the customer login)

Before Mode A, `Customer\AuthController::login()` checked the PIN with `Hash::check()`
and only had the per-identifier + IP rate limit; it now goes through
`PinService::verify()` (in `CustomerAuthService::verifyPinCredentials()`). Since the
Firebase customer login it **no longer issues a token**: after a correct PIN it answers
`403 activation_required` or `403 firebase_login_required`. The failure responses are
unchanged:

| Situation | Response |
| --- | --- |
| Unknown NIS/account number, or wrong PIN | `422` `errors.identifier: "NIS/nomor rekening atau PIN salah."`. The remaining attempts are **not** revealed at login |
| Locked (including the attempt that causes the lock) | `422` `errors.identifier: "Terlalu banyak percobaan PIN salah. Coba lagi pukul HH:MM."` |
| Correct PIN, account `inactive` | `422` `"Rekening ini sudah tidak aktif."` (only shown after the correct PIN) |

The lookup tries the account number first, then the NIS, so both identifiers hit the
same customer and the same counter. The `throttle:login` limiter (5/min per identifier +
IP) still applies on top.

---

## 3. Mode A: mobile approval

### 3.1 The authorization record

Table `withdrawal_authorizations` (model `WithdrawalAuthorization`). One row is one
withdrawal request, bound to **one teller, one customer, one amount and one shift**:

| Column | Purpose |
| --- | --- |
| `public_id` (ULID, unique) | The `{id}` used in URLs and responses. Access is still enforced by ownership, not by secrecy |
| `teller_id`, `customer_id`, `daily_report_id` | Binding (FK, `ON DELETE RESTRICT`) |
| `amount` | Bound amount (`CHECK amount > 0`). Completion takes the amount **from this row**, never from the request |
| `status` | `pending`, `approved`, `rejected`, `cancelled`, `expired`, `consumed` |
| `expires_at` | Deadline for the customer's decision (created + 120 s) |
| `approved_at`, `approval_expires_at` | Approval time; deadline for the teller to complete (approved + 120 s) |
| `rejected_at`, `cancelled_at`, `cancel_reason` | `cancel_reason` is `teller` or `shift_closed` |
| `consumed_at`, `transaction_id` (unique, FK) | The single withdrawal it produced |

Database CHECKs: `consumed` ⇔ `transaction_id` and `consumed_at` set; `approved` and
`consumed` rows have the approval times. The model refuses updates to rows whose status
is already final and refuses deletes, so finished requests are a permanent audit record.

### 3.2 Lifecycle

```text
pending ──customer approves (PIN)──▶ approved ──teller completes──▶ consumed
   ├── customer rejects ──────────▶ rejected
   ├── teller cancels / shift closes ─▶ cancelled ◀── also from approved
   └── 120 s pass ─────────────────▶ expired   ◀── also from approved (120 s after approval)
```

Expiry is applied **lazily** on every read and action (so clients always see the real
status), and by the scheduled command `bank:expire-withdrawal-authorizations` (every
minute) so abandoned rows don't stay `pending` in the database.

### 3.3 Endpoints

| Actor | Endpoint | Behavior |
| --- | --- | --- |
| Teller | `POST /api/teller/withdrawal-authorizations` `{account_number, amount}` | Requires mobile mode. Checks before bothering the customer: open shift for today, no other open request of this teller, no open request for this customer, account `active`, PIN not locked, `balance − amount ≥ min_balance`. `201` with `status: pending`. No money moves |
| Teller | `GET /api/teller/withdrawal-authorizations` | Own requests of today (paginated); `?status=open` returns only the one in progress |
| Teller | `GET /api/teller/withdrawal-authorizations/{id}` | Status for polling (own requests only) |
| Teller | `POST /api/teller/withdrawal-authorizations/{id}/cancel` | `pending`/`approved` → `cancelled` |
| Teller | `POST /api/teller/withdrawal-authorizations/{id}/complete` | Executes the approved withdrawal. `201` Transaction the first time, `200` with the **same** transaction on retry |
| Customer | `GET /api/customer/withdrawal-authorizations` | Own open requests (`pending`/`approved`) with amount, balance, balance after, teller name, deadlines |
| Customer | `GET /api/customer/withdrawal-authorizations/{id}` | One own request (any status) |
| Customer | `POST /api/customer/withdrawal-authorizations/{id}/approve` `{pin}` | PIN check (shared lock) → `approved`. Wrong PIN: `422` with `remaining_attempts`; locked: `422` with `pin_locked_until`. The request stays `pending` after a wrong PIN |
| Customer | `POST /api/customer/withdrawal-authorizations/{id}/reject` | `pending` → `rejected` |

Another teller's or customer's request answers `404`, as if it didn't exist. Full
request/response shapes are in [API.md](API.md#withdrawal-authorizations-mobile-approval).

### 3.4 Sequence

```text
Teller (web)                    Backend                               Customer (Expo)
  │ POST authorizations ─────────▶ checks shift / status / lock / balance
  │ ◀── 201 {id, pending, expires_at, server_time}
  │ poll GET authorizations/{id}                       poll GET /customer/withdrawal-authorizations
  │   every 2 s                                        (while the app is open, or on "Refresh")
  │                                                    shows amount, teller, balance after → PIN
  │                               ◀── POST .../{id}/approve {pin} ──
  │                               PinService::verify → pending → approved
  │ ◀── status approved
  │ POST .../{id}/complete ───────▶ lock + consume + withdraw + journal (one DB transaction)
  │ ◀── 201 Transaction (receipt)
```

### 3.5 Concurrency and exactly-once completion

- **Create** locks in the same order as transactions (teller → shift → customer), then
  checks for open requests. Two tellers creating for the same customer at the same time
  are serialized by the customer row lock; the second gets `422`.
- **Approve, reject, cancel** are compare-and-set updates
  (`UPDATE … WHERE status = <expected> [AND expires_at > now]`). If two of them race,
  exactly one changes the row; the other gets `422` with the current status.
- **Complete** runs inside `TransactionService::record()` in one DB transaction:
  1. lock teller and today's shift;
  2. lock the authorization row (`SELECT … FOR UPDATE`); if it is already `consumed`,
     return its transaction; otherwise it must be `approved`, not past
     `approval_expires_at`, and belong to this shift;
  3. lock the customer and run the normal withdrawal checks (status, minimum balance);
  4. insert the transaction, then `UPDATE … SET status = consumed, transaction_id = …
     WHERE status = approved`; anything other than one affected row aborts;
  5. write the two journal lines.

  Any failure rolls back all of it, so the authorization stays `approved` and the
  teller can retry until it expires. The unique `transaction_id` is a final database
  guard.
- **Shift close** cancels the shift's open requests (`cancel_reason: shift_closed`)
  in the same DB transaction as the close.

### 3.6 Rate limits (named limiters, per actor)

| Limiter | Routes | Limit |
| --- | --- | --- |
| `withdrawal-authorization-create` | teller create | 10/min per teller |
| `withdrawal-authorization-poll` | teller list/show/cancel/complete, customer list/show | 120/min per actor |
| `withdrawal-authorization-decide` | customer approve/reject | 5/min per customer |

Keys include the actor type, so a teller and a customer with the same numeric id never
share a counter. The PIN lock (3 wrong) is the real brute-force protection; the limiter
only caps request volume.

---

## 4. Mode B: PIN at the teller terminal

`POST /api/teller/withdrawals` `{account_number, amount, pin}` is unchanged, but it is
now refused with `422` unless `withdrawal_verification` is `terminal` or `both`. Behavior
when enabled:

1. If an `Idempotency-Key` matches an earlier withdrawal by this teller for the same
   customer and amount, that transaction is returned (`200`) without re-checking the PIN.
2. `PinService::verify()` (shared lock; counted even if the withdrawal then fails).
3. One DB transaction: teller → shift → customer locks, status, minimum balance, insert,
   journal.

Known limitation (**L2**, not changed in this phase): the PIN is checked before the
shift, status and balance checks, so a wrong PIN counts even when the withdrawal would
have failed anyway. Mode A does not have this problem because the request is pre-checked
when it is created.

Terminal withdrawals have no `withdrawal_authorization` in the transaction resource;
Mode A withdrawals show `withdrawal_authorization: {id, method: "mobile", approved_at}`.

---

## 5. Security properties

| Requirement | How it is met |
| --- | --- |
| Customer cannot initiate a withdrawal | Only teller routes create requests or transactions (`staff:teller`); customer routes can only list, approve or reject |
| Staff cannot use the customer's PIN function | Approve/reject are behind the `customer` middleware; staff tokens get `403` (tested for all three roles) |
| PIN never stored in plaintext | bcrypt hash only; the authorization table has no PIN column |
| PIN never in responses or logs | No resource includes `pin`; validation errors don't echo values; no request logging (tested: no response contains `"pin` or the PIN value) |
| PIN never sent to teller clients (Mode A) | The PIN goes only from the customer's phone to `approve` |
| Brute force | Shared 3-attempt / 15-minute lock across the NIS + PIN check, app unlock, invitation resend, approval, withdrawal and PIN change, persisted even on failure; plus rate limits |
| Approving a different withdrawal | The customer sees amount, teller and balance after before approving; completion uses the amount and account stored in the request |
| Replay / reuse | One request → at most one transaction (status guard, row lock, unique `transaction_id`); `complete` retries return the same transaction |
| Stale approvals | 120 s to approve, 120 s to complete; requests die with the shift |
| Cross-teller / cross-customer access | Ownership checks return `404`; IDs are ULIDs |
| Transport | HTTPS is a deployment matter (unchanged) |

---

## 6. Remaining limitations

1. **Polling only.** The customer must have the app open (or tap refresh) to see a new
   request; there is no push notification. Acceptable for a request made while the
   customer stands at the counter.
2. **Customer tokens have no abilities.** Any device where the customer is logged in can
   approve, and sessions now last `CUSTOMER_SESSION_MINUTES` (default 30 days). The PIN is
   still required for every approval. Optional client-side hardening: device biometrics
   before showing the PIN pad; `POST /customer/logout-all` ends every session.
3. **L2 in Mode B** (above).
4. **Graduated customer during a request.** If an administrator closes (soft deletes) the
   account between approval and completion, `complete` answers `404`; the request then
   expires. Requests are short-lived, so this is unlikely.
5. **No supervisor/admin list of authorizations.** The authorization is visible on the
   audit transaction detail only (decision D7).

---

## 7. Configuration

| Item | Where | Default |
| --- | --- | --- |
| Verification mode | Setting `withdrawal_verification` (`PATCH /api/admin/settings`) | `mobile` |
| Customer decision window | `BANK_WITHDRAWAL_AUTH_PENDING_SECONDS` | 120 |
| Teller completion window | `BANK_WITHDRAWAL_AUTH_APPROVED_SECONDS` | 120 |
| Wrong PINs before lock | `BANK_PIN_MAX_ATTEMPTS` | 3 |
| Lock duration | `BANK_PIN_LOCK_MINUTES` | 15 |
| Expiry sweep | `bank:expire-withdrawal-authorizations`, every minute (needs `php artisan schedule:work` or cron) | — |

---

## 8. Decisions taken

| # | Decision |
| --- | --- |
| D1 | Setting `withdrawal_verification` = `mobile` \| `terminal` \| `both`, default `mobile`. `POST /teller/withdrawals` (PIN at the terminal) only works in `terminal`/`both` |
| D2 | One shared PIN lock for the NIS + PIN check, app unlock, invitation resend, approval, withdrawal and PIN change |
| D3 | 120 s to approve, 120 s to complete after approval |
| D4 | At most one open request per customer and per teller |
| D5 | The NIS + PIN check shows a generic error and reveals only an actual lock, not the remaining attempts (the app unlock, being inside a session, returns `remaining_attempts` like approval) |
| D6 | No match code between screens: the customer confirms amount and teller name |
| D7 | No authorization list for supervisors/admins; shown on the audit transaction detail |
| D8 | This audit was committed before implementation (`c405986`) |

Mode B as a recorded, supervisor-approved fallback, push notifications and deposit
approval are out of scope for this phase.
