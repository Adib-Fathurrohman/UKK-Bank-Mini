<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DailyReport extends Model
{
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'teller_id',
        'report_date',
        'transaction_count',
        'total_deposit',
        'total_withdrawal',
        'status',
        'submitted_at',
        'supervisor_id',
        'reviewed_at',
        'supervisor_note',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'transaction_count' => 'integer',
            'total_deposit' => 'integer',
            'total_withdrawal' => 'integer',
            'status' => ReportStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * An approved report is final. Rejected reports may be resubmitted.
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
}
