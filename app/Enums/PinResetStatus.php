<?php

namespace App\Enums;

/**
 * Lifecycle of a forgotten-PIN request:
 *
 * pending ──admin approves──▶ approved ──customer sets new PIN (after the wait)──▶ completed
 *    │                           │
 *    ├── admin rejects ─▶ rejected
 *    ├── customer cancels / admin resets the PIN ─▶ cancelled ◀── (also from approved)
 *    └── time runs out ─▶ expired ◀── (also from approved)
 */
enum PinResetStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case COMPLETED = 'completed';

    /**
     * Still waiting for the administrator or the customer.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::APPROVED], true);
    }

    /**
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::PENDING, self::APPROVED];
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu persetujuan admin',
            self::APPROVED => 'Disetujui admin',
            self::REJECTED => 'Ditolak admin',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
            self::COMPLETED => 'Selesai',
        };
    }
}
