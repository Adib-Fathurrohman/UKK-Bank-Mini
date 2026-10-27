<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\TransactionTypes;
use LogicException;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'daily_report_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
    ];

    protected $casts = [
        'type' => TransactionTypes::class,
        'amount' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
    ];

    /**
     * Transactions are an audit trail: once written they are never changed or removed.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transaksi yang sudah tercatat tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Transaksi yang sudah tercatat tidak dapat dihapus.'));
    }

    /**
     * Includes closed (soft deleted) accounts so history stays readable after graduation.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * The staff member who executed the transaction.
     */
    public function teller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function accountingJournals()
    {
        return $this->hasMany(AccountingJournal::class);
    }
}
