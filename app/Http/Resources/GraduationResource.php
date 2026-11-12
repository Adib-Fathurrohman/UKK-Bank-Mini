<?php

namespace App\Http\Resources;

use App\Support\Terbilang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A graduation batch; with `customers` loaded, every closed account and its payout.
 *
 * @mixin \App\Models\Graduation
 */
class GraduationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'label' => $this->label(),
            'class' => $this->class,
            'major' => $this->major ? ['id' => $this->major->id, 'code' => $this->major->code, 'major_name' => $this->major->major_name] : null,
            'group' => $this->group,
            'customer_count' => $this->customer_count,
            'paid_count' => $this->paid_count,
            'total_amount' => $this->total_amount,
            'total_amount_in_words' => Terbilang::rupiah($this->total_amount),
            'note' => $this->note,
            'executed_by' => $this->whenLoaded('executor', fn () => ['id' => $this->executor->id, 'full_name' => $this->executor->full_name]),
            'created_at' => $this->created_at,
            'customers' => $this->whenLoaded('customers', function () {
                $payouts = $this->transactions->keyBy('customer_id');

                return $this->customers->map(fn ($customer) => [
                    'id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'nis' => $customer->nis,
                    'full_name' => $customer->full_name,
                    'class' => $customer->class,
                    'major_code' => $customer->major?->code,
                    'group' => $customer->group,
                    // 0 when the account was empty: it was only closed.
                    'amount' => $payouts->get($customer->id)?->amount ?? 0,
                    'transaction_id' => $payouts->get($customer->id)?->id,
                ])->values()->all();
            }),
        ];
    }
}
