<?php

namespace App\Models;

use App\Enums\CashCountStage;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One teller's shift (piket) and its end-of-day report.
 */
class DailyReport extends Model
{
    protected $attributes = [
        'status' => 'open',
        'opening_balance' => 0,
        'transaction_count' => 0,
        'total_deposit' => 0,
        'total_withdrawal' => 0,
    ];

    protected $fillable = [
        'teller_id',
        'report_date',
        'status',
        'opening_balance',
        'opened_at',
        'transaction_count',
        'total_deposit',
        'total_withdrawal',
        'system_balance',
        'physical_balance',
        'submitted_at',
        'supervisor_id',
        'reviewed_at',
        'supervisor_note',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'status' => ReportStatus::class,
            'opening_balance' => 'integer',
            'opened_at' => 'datetime',
            'transaction_count' => 'integer',
            'total_deposit' => 'integer',
            'total_withdrawal' => 'integer',
            'system_balance' => 'integer',
            'physical_balance' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * An approved report is final. Reports are never deleted.
     */
    protected static function booted(): void
    {
        static::updating(function (DailyReport $report) {
            if ($report->getOriginal('status') === ReportStatus::APPROVED) {
                throw new LogicException('Laporan yang sudah disetujui tidak dapat diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Laporan harian tidak dapat dihapus.'));
    }

    public function teller()
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function cashCounts()
    {
        return $this->hasMany(CashCount::class)->orderByDesc('denomination');
    }

    public function openingCounts()
    {
        return $this->cashCounts()->where('stage', CashCountStage::OPENING);
    }

    public function closingCounts()
    {
        return $this->cashCounts()->where('stage', CashCountStage::CLOSING);
    }

    public function cashTransfer()
    {
        return $this->hasOne(CashTransfer::class);
    }

    /**
     * Physical cash minus system balance; null until the shift is closed.
     */
    public function difference(): ?int
    {
        return $this->physical_balance === null || $this->system_balance === null
            ? null
            : $this->physical_balance - $this->system_balance;
    }
}
