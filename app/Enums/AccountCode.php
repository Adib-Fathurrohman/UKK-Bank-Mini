<?php

namespace App\Enums;

/**
 * Chart of accounts used by the double-entry journal.
 * Which side (debit/credit) an entry is on is stored separately in Position.
 */
enum AccountCode: string
{
    case CASH = '101';
    case CUSTOMER_SAVINGS = '201';

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Kas',
            self::CUSTOMER_SAVINGS => 'Tabungan Nasabah',
        };
    }
}
