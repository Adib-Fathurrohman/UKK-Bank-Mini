<?php

namespace App\Enums;

/**
 * Lifecycle of a withdrawal authorization:
 *
 * pending ──customer approves──▶ approved ──teller completes──▶ consumed
 *    │                              │
 *    ├── customer rejects ─▶ rejected
 *    ├── teller cancels / shift closes ─▶ cancelled ◀── (also from approved)
 *    └── time runs out ─▶ expired ◀── (also from approved)
 */
enum WithdrawalAuthorizationStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case CONSUMED = 'consumed';

    /**
     * Still waiting for the customer or the teller.
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
            self::PENDING => 'Menunggu persetujuan nasabah',
            self::APPROVED => 'Disetujui nasabah',
            self::REJECTED => 'Ditolak nasabah',
            self::CANCELLED => 'Dibatalkan',
            self::EXPIRED => 'Kedaluwarsa',
            self::CONSUMED => 'Selesai',
        };
    }
}
