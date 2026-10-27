<?php

namespace App\Enums;

/**
 * Lifecycle of a teller's shift (daily report):
 *
 * open ──close──▶ submitted ──approve──▶ approved (final, cash moved to the vault)
 *   ▲                 │  ▲
 *   │               hold │
 *   │                 ▼  │
 *   └──── rejected ◀── on_hold
 *
 * A rejected shift works like an open one: the teller corrects and closes again.
 */
enum ReportStatus: string
{
    case OPEN = 'open';
    case SUBMITTED = 'submitted';
    case ON_HOLD = 'on_hold';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Berjalan',
            self::SUBMITTED => 'Menunggu verifikasi',
            self::ON_HOLD => 'Ditahan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
        };
    }

    /**
     * The teller is still working in this shift.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::OPEN, self::REJECTED], true);
    }

    /**
     * Waiting for a supervisor decision.
     */
    public function isAwaitingReview(): bool
    {
        return in_array($this, [self::SUBMITTED, self::ON_HOLD], true);
    }
}
