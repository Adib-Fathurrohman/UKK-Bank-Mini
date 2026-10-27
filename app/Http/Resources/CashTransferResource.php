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
            'transferred_at' => $this->transferred_at,
            'daily_report' => $this->whenLoaded('dailyReport', fn () => [
                'id' => $this->dailyReport->id,
                'report_date' => $this->dailyReport->report_date->toDateString(),
                'teller' => $this->dailyReport->relationLoaded('teller') ? [
                    'id' => $this->dailyReport->teller->id,
                    'full_name' => $this->dailyReport->teller->full_name,
                ] : null,
            ]),
            'approved_by' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver->id,
                'full_name' => $this->approver->full_name,
            ]),
        ];
    }
}
