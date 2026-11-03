<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An FCM registration token of one of the customer's phones. It belongs to
 * the session (Sanctum token) that registered it and disappears with it.
 */
class CustomerPushToken extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = [
        'customer_id',
        'personal_access_token_id',
        'token',
        'platform',
    ];

    protected $hidden = [
        'token',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function accessToken()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
