<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AccountingJournal
 */
class JournalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'account_code' => $this->account_code,
            'account_name' => $this->account_code->label(),
            'position' => $this->position,
            'amount' => $this->amount,
            'created_at' => $this->created_at,
            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => $this->transaction->id,
                'type' => $this->transaction->type,
                'customer_id' => $this->transaction->customer_id,
                'user_id' => $this->transaction->user_id,
                'customer' => $this->transaction->relationLoaded('customer') ? [
                    'account_number' => $this->transaction->customer->account_number,
                    'full_name' => $this->transaction->customer->full_name,
                ] : null,
                'teller' => $this->transaction->relationLoaded('teller') ? [
                    'full_name' => $this->transaction->teller->full_name,
                ] : null,
            ]),
        ];
    }
}
