<?php

namespace App\Models;

use App\Enums\WithdrawalAuthorizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

/**
 * A withdrawal a teller started and the customer approves in the mobile app.
 * Status changes go through App\Services\WithdrawalAuthorizationService.
 */
class WithdrawalAuthorization extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'teller_id',
        'customer_id',
        'daily_report_id',
        'amount',
        'status',
        'expires_at',
        'approved_at',
        'approval_expires_at',
        'rejected_at',
        'cancelled_at',
        'cancel_reason',
        'consumed_at',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => WithdrawalAuthorizationStatus::class,
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'approval_expires_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WithdrawalAuthorization $authorization) {
            $authorization->public_id ??= (string) Str::ulid();
        });
        // Finished authorizations (rejected, cancelled, expired, consumed) are audit records.
        static::updating(function (WithdrawalAuthorization $authorization) {
            if (! $authorization->getOriginal('status')->isOpen()) {
                throw new LogicException('Otorisasi penarikan yang sudah selesai tidak dapat diubah.');
            }
        });
        static::deleting(fn () => throw new LogicException('Otorisasi penarikan tidak dapat dihapus.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function teller()
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The moment this authorization stops being usable in its current status.
     */
    public function deadline(): ?\Carbon\CarbonInterface
    {
        return match ($this->status) {
            WithdrawalAuthorizationStatus::PENDING => $this->expires_at,
            WithdrawalAuthorizationStatus::APPROVED => $this->approval_expires_at,
            default => null,
        };
    }

    public function isPastDeadline(): bool
    {
        return $this->deadline()?->lte(now()) ?? false;
    }
}
