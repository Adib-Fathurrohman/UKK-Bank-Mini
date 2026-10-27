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
            'type_label' => $this->type->label(),
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'created_at' => $this->created_at,
            'daily_report_id' => $this->daily_report_id,
            'corrects_transaction_id' => $this->corrects_transaction_id,
            'note' => $this->note,
            'corrected_by' => $this->whenLoaded('correction', fn () => $this->correction ? [
                'id' => $this->correction->id,
                'type' => $this->correction->type,
                'created_at' => $this->correction->created_at,
            ] : null),
            'authorized_by' => $this->whenLoaded('authorizer', fn () => $this->authorizer ? [
                'id' => $this->authorizer->id,
                'full_name' => $this->authorizer->full_name,
            ] : null),
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
