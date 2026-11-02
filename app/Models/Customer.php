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
     * Customers authenticate with a 6-digit PIN instead of a password.
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
        'firebase_uid',
        'email_verified_at',
        'activated_at',
        'class',
        'major_id',
        'group',
        'account_status',
        'pin',
        'pin_failed_attempts',
        'pin_locked_until',
        'balance',
    ];

    protected $hidden = [
        'pin',
        'firebase_uid',
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'pin' => 'hashed',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'datetime',
            'balance' => 'integer',
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * The customer has bound a Firebase account and can log in to the app.
     * Before that the account is "pending": it works at the counter, but the
     * app login is not available yet.
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
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

    public function activationTokens()
    {
        return $this->hasMany(CustomerActivationToken::class);
    }
}
