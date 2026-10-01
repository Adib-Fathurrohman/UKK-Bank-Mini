<?php

namespace App\Models;

use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'account_number',
        'nis',
        'full_name',
        'class',
        'major_id',
        'group',
        'account_status',
        'pin',
        'balance',
    ];

    protected $hidden = [
        'pin',
    ];

    protected function casts(): array
    {
        return [
            'account_status' => AccountStatus::class,
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

