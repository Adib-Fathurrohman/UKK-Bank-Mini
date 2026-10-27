<?php

namespace App\Models;

use App\Enums\CashCountStage;
use Illuminate\Database\Eloquent\Model;

/**
 * How many notes or coins of one denomination were counted at the opening
 * or closing of a shift.
 */
class CashCount extends Model
{
    protected $fillable = [
        'daily_report_id',
        'stage',
        'denomination',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'stage' => CashCountStage::class,
            'denomination' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }
}
