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
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
            'pin' => 'hashed',
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
}
