<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * A graduation batch (kelulusan): the accounts of one class (optionally one
 * major and group) were paid out in full from the main vault and closed.
 * Created by App\Services\GraduationService; never changed afterwards.
 */
class Graduation extends Model
{
    protected $fillable = [
        'executed_by',
        'class',
        'major_id',
        'group',
        'customer_count',
        'paid_count',
        'total_amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'customer_count' => 'integer',
            'paid_count' => 'integer',
            'total_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Graduation $graduation) {
            $graduation->public_id ??= (string) Str::ulid();
        });
        static::updating(fn () => throw new LogicException('Data kelulusan tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Data kelulusan tidak dapat dihapus.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    /**
     * The payouts (one withdrawal per account that still held money).
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Every account closed by this batch, including those with nothing to pay out.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class)->withTrashed();
    }

    /**
     * "XII RPL 2", "XII" ... as printed on the slips.
     */
    public function label(): string
    {
        return trim(implode(' ', array_filter([$this->class, $this->major?->code, $this->group])));
    }
}
