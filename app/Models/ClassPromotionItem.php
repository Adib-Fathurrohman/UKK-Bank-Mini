<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One account in a kenaikan kelas run: its class before and after
 * (the same class for a student who stays).
 */
class ClassPromotionItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['class_promotion_id', 'customer_id', 'from_class', 'to_class'];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function isRetained(): bool
    {
        return $this->from_class === $this->to_class;
    }
}
