<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One upload of customer data from the old bookkeeping, with the customers
 * and the transaction history it created.
 */
class CustomerImport extends Model
{
    protected $fillable = [
        'imported_by',
        'file_name',
        'customer_count',
        'transaction_count',
    ];

    protected $casts = [
        'customer_count' => 'integer',
        'transaction_count' => 'integer',
    ];

    /**
     * Part of the audit trail, like the transactions it created.
     */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Riwayat impor tidak dapat dihapus.'));
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
