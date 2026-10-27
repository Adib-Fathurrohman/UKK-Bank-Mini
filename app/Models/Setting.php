<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One global configuration value. Read and write through App\Services\SettingsService,
 * which knows each key's type and default.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
