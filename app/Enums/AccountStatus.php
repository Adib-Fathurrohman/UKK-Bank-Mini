<?php

namespace App\Enums;

enum AccountStatus: string
{
    //
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::SUSPENDED => 'Ditangguhkan',
            self::INACTIVE => 'Nonaktif',
        };
    }
}
