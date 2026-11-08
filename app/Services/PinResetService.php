<?php

namespace App\Services;

use App\Contracts\PushNotifier;
use App\Enums\PinResetStatus as Status;
use App\Exceptions\PinNotSetException;
use App\Exceptions\PinResetException;
use App\Models\Customer;
use App\Models\PinResetRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Forgotten PIN, self-service with two safeguards (see config bank.pin_reset_*):
 *
 *   1. an administrator must approve the request, and
 *   2. the new PIN can only be set once the waiting period has passed.
 *
 * The caller has already checked the customer's Firebase re-authentication for
 * request() and complete(). While a request is open, withdrawals are paused
 * (ensureWithdrawalsAllowed): whoever asked for the reset may have learned the
 * old PIN, and the real owner is told by push and can cancel.
 *
 * The new PIN only travels from the phone to complete(); it is never stored
 * here. Every status change is a compare-and-set, so concurrent requests
 * cannot move a request twice.
 */
class PinResetService
{
    public function __construct(private PushNotifier $push)
    {
    }

    /**
     * The customer asks for a reset. One open request at a time.
     */
    public function request(Customer $customer): PinResetRequest
    {
        $request = DB::transaction(function () use ($customer) {
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();

            // Without a PIN there is nothing to reset: the customer creates one directly.
            if (! $customer->hasPin()) {
                throw new PinNotSetException;
            }

            $this->expireOverdue(PinResetRequest::where('customer_id', $customer->id));
            if ($this->openFor($customer)) {
                throw PinResetException::alreadyOpen();
            }

            return PinResetRequest::create([
                'customer_id' => $customer->id,
                'status' => Status::PENDING,
                'open_customer_id' => $customer->id,
                'available_at' => now()->addHours(config('bank.pin_reset_wait_hours')),
                'expires_at' => now()->addDays(config('bank.pin_reset_valid_days')),
            ]);
        });

        $this->notify($request, 'Permintaan reset PIN', 'Ada permintaan reset PIN untuk rekening Anda. Jika bukan Anda, buka aplikasi dan batalkan permintaan sekarang.');

        return $request;
    }

    /**
     * An administrator approves. The customer can still only complete it after available_at.
     */
    public function approve(PinResetRequest $request, User $admin, ?string $note = null): PinResetRequest
    {
        $request = $this->transition($request, [Status::PENDING], [
            'status' => Status::APPROVED,
            'decided_by' => $admin->id,
            'approved_at' => now(),
            'decision_note' => $note,
        ]);

        $this->notify($request, 'Reset PIN disetujui', 'Permintaan reset PIN disetujui. Anda dapat membuat PIN baru di aplikasi mulai '.$request->available_at->timezone(config('app.timezone'))->format('d-m-Y H:i').'.');

        return $request;
    }

    public function reject(PinResetRequest $request, User $admin, ?string $note = null): PinResetRequest
    {
        $request = $this->transition($request, [Status::PENDING], [
            'status' => Status::REJECTED,
            'open_customer_id' => null,
            'decided_by' => $admin->id,
            'rejected_at' => now(),
            'decision_note' => $note,
        ]);

        $this->notify($request, 'Reset PIN ditolak', 'Permintaan reset PIN ditolak. Hubungi petugas bank mini bila perlu.');

        return $request;
    }

    /**
     * The customer withdraws the request (e.g. it was not them), pending or approved.
     */
    public function cancel(PinResetRequest $request, string $reason = 'customer'): PinResetRequest
    {
        return $this->transition($request, Status::open(), [
            'status' => Status::CANCELLED,
            'open_customer_id' => null,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);
    }

    /**
     * Set the new PIN: only for an approved request whose waiting period is over.
     */
    public function complete(PinResetRequest $request, Customer $customer, string $pin): PinResetRequest
    {
        $request = DB::transaction(function () use ($request, $customer, $pin) {
            $customer = Customer::whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $request = $this->current($request);

            if ($request->status === Status::PENDING) {
                throw PinResetException::notApproved();
            }
            if ($request->status !== Status::APPROVED) {
                throw PinResetException::notUsable($request);
            }
            if (! $request->isReady()) {
                throw PinResetException::notReady($request);
            }

            $updated = PinResetRequest::whereKey($request->id)
                ->where('status', Status::APPROVED)
                ->update([
                    'status' => Status::COMPLETED,
                    'open_customer_id' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw PinResetException::notUsable($this->current($request));
            }

            // The old PIN and any lock from wrong attempts are replaced together.
            $customer->update(['pin' => $pin, 'pin_failed_attempts' => 0, 'pin_locked_until' => null]);

            return $this->current($request);
        });

        $this->notify($request, 'PIN diubah', 'PIN rekening Anda telah diganti. Jika bukan Anda, segera hubungi petugas bank mini.');

        return $request;
    }

    /**
     * The customer's open request, if any (overdue ones are expired first).
     */
    public function openFor(Customer $customer): ?PinResetRequest
    {
        $this->expireOverdue(PinResetRequest::where('customer_id', $customer->id));

        return PinResetRequest::where('customer_id', $customer->id)
            ->whereIn('status', Status::open())
            ->latest('id')
            ->first();
    }

    /**
     * Withdrawals (approval in the app, teller authorization, terminal) are
     * paused while a reset request is open.
     */
    public function ensureWithdrawalsAllowed(Customer $customer): void
    {
        if ($this->openFor($customer)) {
            throw PinResetException::withdrawalsPaused();
        }
    }

    /**
     * An administrator reset removes the PIN itself, so open requests have no purpose left.
     */
    public function cancelOpenFor(Customer $customer, string $reason): int
    {
        return PinResetRequest::where('customer_id', $customer->id)
            ->whereIn('status', Status::open())
            ->update([
                'status' => Status::CANCELLED,
                'open_customer_id' => null,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    /**
     * Mark overdue open requests as expired. Also done lazily whenever one is read.
     */
    public function expireOverdue($query = null): int
    {
        return ($query ?? PinResetRequest::query())
            ->whereIn('status', Status::open())
            ->where('expires_at', '<=', now())
            ->update([
                'status' => Status::EXPIRED,
                'open_customer_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * The request as it is now, with overdue ones expired first.
     */
    public function current(PinResetRequest $request): PinResetRequest
    {
        $this->expireOverdue(PinResetRequest::whereKey($request->id));

        return $request->fresh();
    }

    /**
     * Compare-and-set status change: only applies if the request is still in one
     * of the allowed statuses and not overdue.
     *
     * @param  array<int, Status>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(PinResetRequest $request, array $from, array $changes): PinResetRequest
    {
        $request = $this->current($request);
        $this->ensureStatus($request, ...$from);

        $updated = PinResetRequest::whereKey($request->id)
            ->where('status', $request->status)
            ->update([...$changes, 'updated_at' => now()]);

        $request = $request->fresh();

        if ($updated === 0) {
            throw PinResetException::notUsable($request);
        }

        return $request;
    }

    private function ensureStatus(PinResetRequest $request, Status ...$allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw PinResetException::notUsable($request);
        }
    }

    /**
     * After the response is sent, so FCM never slows down or fails the request.
     */
    private function notify(PinResetRequest $request, string $title, string $body): void
    {
        defer(fn () => rescue(fn () => $this->push->notify(
            $request->customer,
            $title,
            $body,
            ['type' => 'pin_reset', 'id' => $request->public_id, 'status' => $request->status->value],
        )));
    }
}
