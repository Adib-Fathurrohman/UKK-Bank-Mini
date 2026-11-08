<?php

namespace App\Models;

use App\Enums\TransactionTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'daily_report_id',
        'customer_import_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'corrects_transaction_id',
        'authorized_by',
        'note',
        'idempotency_key',
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

    /**
     * The teller's shift; null for history brought in by a customer import.
     */
    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    /**
     * For history from the old bookkeeping: the import that created it.
     */
    public function customerImport()
    {
        return $this->belongsTo(CustomerImport::class);
    }

    public function isImported(): bool
    {
        return $this->customer_import_id !== null;
    }

    /**
     * For a correction: the transaction it reverses.
     */
    public function corrects()
    {
        return $this->belongsTo(Transaction::class, 'corrects_transaction_id');
    }

    /**
     * For an ordinary transaction: the correction that reversed it, if any.
     */
    public function correction()
    {
        return $this->hasOne(Transaction::class, 'corrects_transaction_id');
    }

    /**
     * For a correction: the supervisor who authorized it.
     */
    public function authorizer()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /**
     * For a withdrawal approved in the mobile app: the customer's authorization.
     */
    public function withdrawalAuthorization()
    {
        return $this->hasOne(WithdrawalAuthorization::class);
    }

    public function accountingJournals()
    {
        return $this->hasMany(AccountingJournal::class);
    }
}
