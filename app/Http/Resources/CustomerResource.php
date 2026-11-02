<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Customer
 */
class CustomerResource extends JsonResource
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
            'email' => $this->email,
            // "pending" until the customer activates the app with a Firebase account.
            'activation_status' => $this->isActivated() ? 'activated' : 'pending',
            'activated_at' => $this->activated_at,
            // The customer creates the PIN in the app after activation; nothing that needs a PIN works before.
            'pin_set' => $this->hasPin(),
            'class' => $this->class,
            'group' => $this->group,
            'major' => $this->whenLoaded('major', fn () => [
                'id' => $this->major->id,
                'code' => $this->major->code,
                'major_name' => $this->major->major_name,
            ]),
            'account_status' => $this->account_status,
            'balance' => $this->balance,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
