<?php

namespace App\Services;

use App\Contracts\FirebaseAuth;
use App\Enums\AccountStatus;
use App\Enums\TransactionTypes;
use App\Enums\WithdrawalAuthorizationStatus;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\GraduationException;
use App\Models\Customer;
use App\Models\Graduation;
use App\Models\User;
use App\Models\WithdrawalAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Graduation (kelulusan): pay out and close the accounts of a graduating class.
 *
 * 1. preview(): the open accounts of the class (optionally one major and group)
 *    and what each will receive: the whole balance, including the minimum
 *    balance that a normal withdrawal must leave.
 * 2. execute(): with the account ids the administrator confirmed in the
 *    preview. In one database transaction every account with money gets one
 *    withdrawal of its whole balance, and every account is closed (inactive,
 *    sessions revoked, soft deleted). If the class changed since the preview
 *    nothing happens.
 *
 * The cash comes from the main vault, handed out with the printed slips, so
 * the withdrawals belong to the graduation batch, not to a teller's shift or
 * drawer. No PIN is asked: it is an administrator's action, recorded with who
 * ran it. A closed account can never be in another batch, so nobody is paid twice.
 */
class GraduationService
{
    public function __construct(
        private JournalService $journals,
        private PinResetService $pinResets,
        private FirebaseAuth $firebase,
    ) {
    }

    /**
     * @param  array{class: string, major_id?: int|null, group?: string|null}  $filters
     * @return array{customers: Collection<int, Customer>, customer_count: int, paid_count: int, total_amount: int}
     */
    public function preview(array $filters): array
    {
        $customers = $this->candidates($filters)->with('major')->orderBy('major_id')->orderBy('group')->orderBy('full_name')->get();

        return [
            'customers' => $customers,
            'customer_count' => $customers->count(),
            'paid_count' => $customers->where('balance', '>', 0)->count(),
            'total_amount' => (int) $customers->sum('balance'),
        ];
    }

    /**
     * @param  array{class: string, major_id?: int|null, group?: string|null}  $filters
     * @param  array<int, int>  $confirmedIds  the accounts shown in the preview
     */
    public function execute(User $admin, array $filters, array $confirmedIds, ?string $note = null): Graduation
    {
        $graduation = DB::transaction(function () use ($admin, $filters, $confirmedIds, $note) {
            $customers = $this->candidates($filters)->orderBy('id')->lockForUpdate()->get();

            if ($customers->isEmpty()) {
                throw GraduationException::nobody();
            }

            $current = $customers->pluck('id')->map(fn ($id) => (int) $id)->all();
            $confirmed = array_map('intval', array_unique($confirmedIds));
            $missing = array_diff($current, $confirmed);
            $unexpected = array_diff($confirmed, $current);

            if ($missing || $unexpected) {
                throw GraduationException::selectionChanged($missing, $unexpected);
            }

            $graduation = Graduation::create([
                'executed_by' => $admin->id,
                'class' => $filters['class'],
                'major_id' => $filters['major_id'] ?? null,
                'group' => $filters['group'] ?? null,
                'customer_count' => $customers->count(),
                'paid_count' => $customers->where('balance', '>', 0)->count(),
                'total_amount' => (int) $customers->sum('balance'),
                'note' => $note,
            ]);

            foreach ($customers as $customer) {
                $this->payOutAndClose($graduation, $customer, $admin);
            }

            // Nothing can be withdrawn from a closed account any more.
            WithdrawalAuthorization::whereIn('customer_id', $current)
                ->whereIn('status', WithdrawalAuthorizationStatus::open())
                ->update([
                    'status' => WithdrawalAuthorizationStatus::CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'account_closed',
                    'updated_at' => now(),
                ]);

            return $graduation;
        });

        // After the commit: stop the app logins. The backend refuses closed accounts
        // anyway, so a Firebase outage is logged for a manual follow-up, not fatal.
        foreach ($graduation->customers()->whereNotNull('firebase_uid')->get() as $customer) {
            try {
                $this->firebase->disableUser($customer->firebase_uid);
            } catch (CustomerAuthException) {
                Log::warning('Could not disable the Firebase user of a graduated customer.', ['customer_id' => $customer->id, 'graduation_id' => $graduation->id]);
            }
        }

        return $graduation;
    }

    private function payOutAndClose(Graduation $graduation, Customer $customer, User $admin): void
    {
        if ($customer->balance > 0) {
            $transaction = $customer->transactions()->create([
                'user_id' => $admin->id,
                'graduation_id' => $graduation->id,
                'type' => TransactionTypes::WITHDRAWAL,
                'amount' => $customer->balance,
                'balance_before' => $customer->balance,
                'balance_after' => 0,
                'note' => 'Penarikan kelulusan '.$graduation->label(),
            ]);

            $this->journals->record($transaction);
        }

        $this->pinResets->cancelOpenFor($customer, 'graduated');

        $customer->update([
            'balance' => 0,
            'account_status' => AccountStatus::INACTIVE,
            'graduation_id' => $graduation->id,
        ]);
        $customer->tokens()->delete();
        $customer->delete();
    }

    /**
     * @param  array{class: string, major_id?: int|null, group?: string|null}  $filters
     */
    private function candidates(array $filters): Builder
    {
        return Customer::query()
            ->where('class', $filters['class'])
            ->when($filters['major_id'] ?? null, fn ($query, $majorId) => $query->where('major_id', $majorId))
            ->when($filters['group'] ?? null, fn ($query, $group) => $query->where('group', $group));
    }
}
