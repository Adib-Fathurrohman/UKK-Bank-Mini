<?php

namespace App\Models;

use App\Enums\PinResetStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * A customer's request to set a new PIN after forgetting it. Status changes
 * go through App\Services\PinResetService.
 */
class PinResetRequest extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'customer_id',
        'status',
        'open_customer_id',
        'available_at',
        'expires_at',
        'decided_by',
        'approved_at',
        'rejected_at',
        'decision_note',
        'cancelled_at',
        'cancel_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PinResetStatus::class,
            'available_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PinResetRequest $request) {
            $request->public_id ??= (string) Str::ulid();
        });
        // Finished requests are audit records.
        static::updating(function (PinResetRequest $request) {
            if (! $request->getOriginal('status')->isOpen()) {
                throw new LogicException('Permintaan reset PIN yang sudah selesai tidak dapat diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Permintaan reset PIN tidak dapat dihapus.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Approved and the waiting period is over: the customer may set the new PIN.
     */
    public function isReady(): bool
    {
        return $this->status === PinResetStatus::APPROVED && $this->available_at->lte(now());
    }
}
