<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Closing cash of a shift moved from the teller's drawer to the main vault.
 * Created automatically when a supervisor approves the daily report, and
 * never changed afterwards.
 */
class CashTransfer extends Model
{
    protected $fillable = [
        'daily_report_id',
        'amount',
        'approved_by',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transferred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pemindahan kas tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Pemindahan kas tidak dapat dihapus.'));
    }

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
