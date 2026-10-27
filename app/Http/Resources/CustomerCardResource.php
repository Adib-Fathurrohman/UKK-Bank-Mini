<?php

namespace App\Http\Resources;

use App\Support\CustomerQr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What is printed on a customer's card and shown as the digital card in the app.
 *
 * @mixin \App\Models\Customer
 */
class CustomerCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_number' => $this->account_number,
            'nis' => $this->nis,
            'full_name' => $this->full_name,
            'class' => $this->class,
            'group' => $this->group,
            'major' => $this->whenLoaded('major', fn () => [
                'code' => $this->major->code,
                'major_name' => $this->major->major_name,
            ]),
            'account_status' => $this->account_status,
            // Encode this string as the QR code; the teller scanner reads it back.
            'qr_payload' => CustomerQr::payload($this->resource),
        ];
    }
}
