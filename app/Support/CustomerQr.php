<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Content of the QR code on a customer's card (and in the mobile app).
 *
 * The code only identifies the account; withdrawals still need the PIN.
 */
final class CustomerQr
{
    public const PREFIX = 'BANKMINI:';

    public static function payload(Customer $customer): string
    {
        return self::PREFIX.$customer->account_number;
    }

    /**
     * The account number inside a scanned payload, or the input unchanged when
     * it was typed by hand (account number or NIS).
     */
    public static function identifierFrom(string $input): string
    {
        $input = trim($input);

        return str_starts_with(strtoupper($input), self::PREFIX)
            ? substr($input, strlen(self::PREFIX))
            : $input;
    }
}
