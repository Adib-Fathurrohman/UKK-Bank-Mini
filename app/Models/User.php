<?php

namespace App\Models;

use App\Enums\UserRoles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'full_name', 'class', 'phone', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Mirror the column defaults so new models have them before a refresh.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRoles::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Transactions executed by this user as teller.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function dailyReports()
    {
        return $this->hasMany(DailyReport::class, 'teller_id');
    }

    public function reviewedDailyReports()
    {
        return $this->hasMany(DailyReport::class, 'supervisor_id');
    }

    public function approvedCashTransfers()
    {
        return $this->hasMany(CashTransfer::class, 'approved_by');
    }
}
