<?php

namespace App\Http\Resources;

use App\Models\CashCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A teller's shift and its cash closing report.
 *
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
            'status' => $this->status,
            'opening_balance' => $this->opening_balance,
            // True: the drawer is tracked per denomination and closing must match it per note.
            'cash_tracked' => $this->cash_tracked,
            'opened_at' => $this->opened_at,
            'transaction_count' => $this->transaction_count,
            'total_deposit' => $this->total_deposit,
            'total_withdrawal' => $this->total_withdrawal,
            'system_balance' => $this->system_balance,
            'physical_balance' => $this->physical_balance,
            'difference' => $this->difference(),
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'supervisor_note' => $this->supervisor_note,
            'teller' => $this->whenLoaded('teller', fn () => [
                'id' => $this->teller->id,
                'full_name' => $this->teller->full_name,
                'class' => $this->teller->class,
            ]),
            'supervisor' => $this->whenLoaded('supervisor', fn () => $this->supervisor ? [
                'id' => $this->supervisor->id,
                'full_name' => $this->supervisor->full_name,
            ] : null),
            'opening_counts' => $this->whenLoaded('openingCounts', fn () => $this->countsToArray($this->openingCounts)),
            'closing_counts' => $this->whenLoaded('closingCounts', fn () => $this->countsToArray($this->closingCounts)),
            'cash_transfer' => $this->whenLoaded('cashTransfer', fn () => $this->cashTransfer ? [
                'id' => $this->cashTransfer->id,
                'amount' => $this->cashTransfer->amount,
                'transferred_at' => $this->cashTransfer->transferred_at,
            ] : null),
        ];
    }

    /**
     * @param  iterable<CashCount>  $counts
     */
    private function countsToArray(iterable $counts): array
    {
        return collect($counts)->map(fn (CashCount $count) => [
            'denomination' => $count->denomination,
            'quantity' => $count->quantity,
            'subtotal' => $count->denomination * $count->quantity,
        ])->values()->all();
    }
}
