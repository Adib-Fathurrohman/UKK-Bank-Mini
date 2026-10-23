<?php

namespace App\Models;

use App\Enums\AccountCode;
use App\Enums\Position;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountingJournal extends Model
{
    protected $fillable = [
        'transaction_id',
        'account_code',
        'position',
        'amount',
    ];

    protected $casts = [
        'account_code' => AccountCode::class,
        'position' => Position::class,
        'amount' => 'integer',
    ];

    /**
     * Journal lines are read-only once written (FR-Acc-03).
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Jurnal akuntansi tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Jurnal akuntansi tidak dapat dihapus.'));
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
