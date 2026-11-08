<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a customer's own account statement (mutasi rekening).
 * Internal details such as the teller and journal entries are left out.
 *
 * @mixin \App\Models\Transaction
 */
class StatementLineResource extends JsonResource
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
            'direction' => $this->type->increasesBalance() ? 'in' : 'out',
            // Imported history keeps the description from the old bookkeeping.
            'description' => $this->isImported() && $this->note ? $this->note : $this->type->label(),
            'imported' => $this->isImported(),
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'created_at' => $this->created_at,
        ];
    }
}
