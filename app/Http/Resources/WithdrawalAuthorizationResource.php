<?php

namespace App\Http\Resources;

use App\Support\Terbilang;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A withdrawal authorization as the teller sees it.
 *
 * @mixin \App\Models\WithdrawalAuthorization
 */
class WithdrawalAuthorizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'amount' => $this->amount,
            'amount_in_words' => Terbilang::rupiah($this->amount),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'account_number' => $this->customer->account_number,
                'full_name' => $this->customer->full_name,
                'class' => $this->customer->class,
            ]),
            'daily_report_id' => $this->daily_report_id,
            'created_at' => $this->created_at,
            'expires_at' => $this->expires_at,
            'approved_at' => $this->approved_at,
            'approval_expires_at' => $this->approval_expires_at,
            'rejected_at' => $this->rejected_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'consumed_at' => $this->consumed_at,
            'transaction_id' => $this->transaction_id,
            // Lets the client run an accurate countdown regardless of its own clock.
            'server_time' => now(),
        ];
    }
}
