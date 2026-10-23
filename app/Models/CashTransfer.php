<?php

namespace App\Models;

use App\Enums\CashTransferStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Physical cash moved from a teller's drawer to the main vault, authorized by a supervisor.
 */
class CashTransfer extends Model
{
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

    public function teller()
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
