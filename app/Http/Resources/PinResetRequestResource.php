<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A forgotten-PIN request. The customer sees their own; the administrator also
 * gets the customer and the decision.
 *
 * @mixin \App\Models\PinResetRequest
 */
class PinResetRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'requested_at' => $this->created_at,
            // The new PIN can be set from here, once approved.
            'available_at' => $this->available_at,
            'ready' => $this->isReady(),
            'expires_at' => $this->expires_at,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'cancelled_at' => $this->cancelled_at,
            'completed_at' => $this->completed_at,
            'decision_note' => $this->decision_note,
            'customer' => $this->when($request->user() instanceof \App\Models\User, fn () => new CustomerSummaryResource($this->customer)),
            'decided_by' => $this->when($request->user() instanceof \App\Models\User, fn () => $this->decider?->full_name),
        ];
    }
}
