<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Major extends Model
{
    protected $fillable = [
        'major_name',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
