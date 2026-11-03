<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses PINs that are guessed first: one repeated digit (000000, 111111)
 * and straight runs (123456, 654321, 345678).
 */
class NotWeakPin implements ValidationRule
{
    public static function isWeak(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::isWeak($value)) {
            $fail('PIN terlalu mudah ditebak. Hindari angka yang sama semua atau berurutan.');
        }
    }
}
