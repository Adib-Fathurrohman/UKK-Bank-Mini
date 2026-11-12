<?php

namespace App\Http\Resources;

use App\Support\Terbilang;
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
            'amount_in_words' => Terbilang::rupiah($this->amount),
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'created_at' => $this->created_at,
            // Null for history brought in by a customer import (no teller shift).
            'daily_report_id' => $this->daily_report_id,
            'imported' => $this->isImported(),
            // Null unless this is a graduation payout (kelulusan): then the batch's public id.
            'graduation_id' => $this->graduation_id ? $this->graduation?->public_id : null,
            'corrects_transaction_id' => $this->corrects_transaction_id,
            // Notes into (deposit, withdrawal correction) or out of (withdrawal, deposit correction)
            // the teller's drawer; empty for shifts before per-note tracking and imports.
            'cash' => $this->whenLoaded('cash', fn () => $this->cash->map(fn ($note) => [
                'denomination' => $note->denomination,
                'quantity' => $note->quantity,
            ])->values()->all()),
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
            // Withdrawals approved by the customer in the mobile app.
            'withdrawal_authorization' => $this->whenLoaded('withdrawalAuthorization', fn () => $this->withdrawalAuthorization ? [
                'id' => $this->withdrawalAuthorization->public_id,
                'method' => 'mobile',
                'approved_at' => $this->withdrawalAuthorization->approved_at,
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
