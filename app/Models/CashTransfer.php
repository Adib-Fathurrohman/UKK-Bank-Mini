<?php

namespace App\Models;

use App\Enums\CashTransferStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Physical cash moved from a teller's drawer to the main vault, authorized by a supervisor.
 */
class CashTransfer extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'teller_id',
        'amount',
        'status',
        'note',
        'supervisor_id',
        'reviewed_at',
        'supervisor_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => CashTransferStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Only a pending transfer can change (to approved or rejected); after that it is final.
     */
    protected static function booted(): void
    {
        static::updating(function (CashTransfer $transfer) {
            if ($transfer->getOriginal('status') !== CashTransferStatus::PENDING) {
                throw new LogicException('Pemindahan kas yang sudah diproses tidak dapat diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Pemindahan kas tidak dapat dihapus.'));
    }

    public function teller()
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
