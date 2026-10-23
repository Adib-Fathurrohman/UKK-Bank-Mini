<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Enums\TransactionTypes;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The staff member who executed the transaction.
     */
    public function teller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function accountingJournals()
    {
        return $this->hasMany(AccountingJournal::class);
    }
}
