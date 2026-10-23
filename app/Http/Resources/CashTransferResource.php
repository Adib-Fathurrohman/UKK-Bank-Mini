<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CashTransfer
 */
class CashTransferResource extends JsonResource
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
            'source' => 'teller_drawer',
            'destination' => 'main_vault',
            'amount' => $this->amount,
            'status' => $this->status,
            'note' => $this->note,
            'created_at' => $this->created_at,
            'reviewed_at' => $this->reviewed_at,
            'supervisor_note' => $this->supervisor_note,
            'teller' => $this->whenLoaded('teller', fn () => [
                'id' => $this->teller->id,
                'full_name' => $this->teller->full_name,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'full_name' => $this->supervisor->full_name,
            ] : null),
        ];
    }
}
