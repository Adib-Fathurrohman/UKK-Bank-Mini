<?php

namespace App\Models;

use App\Enums\AccountCode;
use App\Enums\Position;
use Illuminate\Database\Eloquent\Model;

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

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
