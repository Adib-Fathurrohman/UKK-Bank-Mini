<?php

namespace App\Http\Resources;

use App\Support\Terbilang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A withdrawal request as the customer reviews it in the mobile app.
 *
 * @mixin \App\Models\WithdrawalAuthorization
 */
class CustomerWithdrawalAuthorizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $balance = $this->customer->balance;

        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'amount' => $this->amount,
            'amount_in_words' => Terbilang::rupiah($this->amount),
            'account_number' => $this->customer->account_number,
            'balance' => $balance,
            // Preview only while the request is open; afterwards the balance already reflects it.
            'balance_after' => $this->status->isOpen() ? $balance - $this->amount : null,
            'teller' => $this->whenLoaded('teller', fn () => [
                'full_name' => $this->teller->full_name,
            ]),
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'approved_at' => $this->approved_at,
            'approval_expires_at' => $this->approval_expires_at,
            'consumed_at' => $this->consumed_at,
            'server_time' => now(),
        ];
    }
}
