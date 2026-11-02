<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An invitation link for first-time activation. See CustomerActivationService.
 */
class CustomerActivationToken extends Model
{
    protected $fillable = [
        'customer_id',
        'token_hash',
        'email',
        'issued_by',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Not used and not revoked (it may still have expired).
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('revoked_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
