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
     * The 6-digit PIN, created by the customer in the app after activation
     * (null until then, and after an administrator resets it).
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
     * Before that the account is "pending": deposits work at the counter, but
     * there is no app login and no PIN yet.
     */
    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    public function hasPin(): bool
    {
        return $this->pin !== null;
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
}
