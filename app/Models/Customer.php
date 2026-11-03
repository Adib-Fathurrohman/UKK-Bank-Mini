<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    /**
     * Customers sign in with their NIS (or account number) and a 6-digit PIN.
     * The system generates a temporary PIN; the customer must replace it at
     * the first login (pin_must_change).
     */
    protected $authPasswordName = 'pin';

    /**
     * Customers only use API tokens, so there is no remember_token column.
     */
    protected $rememberTokenName = '';

    protected $fillable = [
        'account_number',
        'nis',
        'full_name',
        'email',
        'class',
        'major_id',
        'group',
        'account_status',
        'pin',
        'pin_must_change',
        'pin_failed_attempts',
        'pin_locked_until',
        'balance',
    ];

    protected $hidden = [
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'pin' => 'hashed',
            'pin_must_change' => 'boolean',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'datetime',
            'balance' => 'integer',
        ];
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function withdrawalAuthorizations()
    {
        return $this->hasMany(WithdrawalAuthorization::class);
    }

    public function pushTokens()
    {
        return $this->hasMany(CustomerPushToken::class);
    }
}
