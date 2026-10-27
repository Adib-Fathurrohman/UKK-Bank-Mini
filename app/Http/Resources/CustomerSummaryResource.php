<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The minimum a teller needs to confirm who is at the counter before a transaction.
 *
 * @mixin \App\Models\Customer
 */
class CustomerSummaryResource extends JsonResource
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
            'account_number' => $this->account_number,
            'nis' => $this->nis,
            'full_name' => $this->full_name,
            'class' => $this->class,
            'group' => $this->group,
            'major_name' => $this->whenLoaded('major', fn () => $this->major->major_name),
            'account_status' => $this->account_status,
            'balance' => $this->balance,
            // Withdrawals are refused until then after too many wrong PINs.
            'pin_locked_until' => $this->pin_locked_until?->isFuture() ? $this->pin_locked_until : null,
        ];
    }
}
