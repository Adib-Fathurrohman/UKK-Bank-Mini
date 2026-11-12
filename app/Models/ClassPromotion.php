<?php

namespace App\Models;

use App\Support\SchoolYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * One kenaikan kelas run (App\Services\ClassPromotionService). Never changed afterwards.
 */
class ClassPromotion extends Model
{
    protected $fillable = ['school_year', 'executed_by', 'promoted_count', 'retained_count', 'note'];

    protected function casts(): array
    {
        return [
            'school_year' => 'integer',
            'promoted_count' => 'integer',
            'retained_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ClassPromotion $promotion) {
            $promotion->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('Data kenaikan kelas tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Data kenaikan kelas tidak dapat dihapus.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function items()
    {
        return $this->hasMany(ClassPromotionItem::class);
    }

    public function schoolYearLabel(): string
    {
        return SchoolYear::label($this->school_year);
    }
}
