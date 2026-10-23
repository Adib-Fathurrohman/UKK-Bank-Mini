<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DailyReport
 */
class DailyReportResource extends JsonResource
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
            'report_date' => $this->report_date->toDateString(),
            'transaction_count' => $this->transaction_count,
            'total_deposit' => $this->total_deposit,
            'total_withdrawal' => $this->total_withdrawal,
            'net_cash' => $this->total_deposit - $this->total_withdrawal,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
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
