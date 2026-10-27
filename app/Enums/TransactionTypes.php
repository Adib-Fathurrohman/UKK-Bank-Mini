<?php

namespace App\Enums;

enum TransactionTypes: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
    // Reverses a deposit typed in error: money goes back out of the account.
    case DEPOSIT_CORRECTION = 'deposit_correction';
    // Reverses a withdrawal typed in error: money goes back into the account.
    case WITHDRAWAL_CORRECTION = 'withdrawal_correction';

    /**
     * Cash comes into the drawer and the customer's balance goes up.
     */
    public function increasesBalance(): bool
    {
        return in_array($this, [self::DEPOSIT, self::WITHDRAWAL_CORRECTION], true);
    }

    public function isCorrection(): bool
    {
        return in_array($this, [self::DEPOSIT_CORRECTION, self::WITHDRAWAL_CORRECTION], true);
    }

    /**
     * The type that reverses this one, or null for corrections (which cannot be corrected again).
     */
    public function correctionType(): ?self
    {
        return match ($this) {
            self::DEPOSIT => self::DEPOSIT_CORRECTION,
            self::WITHDRAWAL => self::WITHDRAWAL_CORRECTION,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Setor tunai',
            self::WITHDRAWAL => 'Tarik tunai',
            self::DEPOSIT_CORRECTION => 'Koreksi setoran',
            self::WITHDRAWAL_CORRECTION => 'Koreksi penarikan',
        };
    }
}
