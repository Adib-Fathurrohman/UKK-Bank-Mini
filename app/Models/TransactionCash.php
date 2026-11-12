<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Notes and coins one transaction moved into or out of the teller's drawer.
 * The direction follows the transaction type (TransactionTypes::addsCash()).
 * Written once together with its transaction and never changed.
 */
class TransactionCash extends Model
{
    protected $table = 'transaction_cash';

    public const UPDATED_AT = null;

    protected $fillable = ['transaction_id', 'denomination', 'quantity'];

    protected function casts(): array
    {
        return [
            'denomination' => 'integer',
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rincian pecahan transaksi tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Rincian pecahan transaksi tidak dapat dihapus.'));
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
