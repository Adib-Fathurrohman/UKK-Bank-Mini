<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Transaction
 */
class TransactionResource extends JsonResource
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
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'created_at' => $this->created_at,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'account_number' => $this->customer->account_number,
                'full_name' => $this->customer->full_name,
            ]),
            'teller' => $this->whenLoaded('teller', fn () => [
                'id' => $this->teller->id,
                'full_name' => $this->teller->full_name,
            ]),
            'journals' => JournalResource::collection($this->whenLoaded('accountingJournals')),
        ];
    }
}
