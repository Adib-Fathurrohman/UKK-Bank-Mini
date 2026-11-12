<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A kenaikan kelas run; with `items` loaded, every account and its move.
 *
 * @mixin \App\Models\ClassPromotion
 */
class ClassPromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'school_year' => $this->schoolYearLabel(),
            'promoted_count' => $this->promoted_count,
            'retained_count' => $this->retained_count,
            'note' => $this->note,
            'executed_by' => $this->whenLoaded('executor', fn () => ['id' => $this->executor->id, 'full_name' => $this->executor->full_name]),
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'customer_id' => $item->customer_id,
                'account_number' => $item->customer->account_number,
                'full_name' => $item->customer->full_name,
                'from_class' => $item->from_class,
                'to_class' => $item->to_class,
                'retained' => $item->isRetained(),
            ])->values()->all()),
        ];
    }
}
