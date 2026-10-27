<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;

/**
 * The customer's PIN was wrong, or PIN entry is locked after too many wrong attempts.
 */
class InvalidPinException extends BankingException
{
    public function __construct(
        string $message,
        public readonly ?int $remainingAttempts = null,
        public readonly ?CarbonInterface $lockedUntil = null,
    ) {
        parent::__construct($message);
    }

    public static function wrong(int $remainingAttempts): self
    {
        return new self("PIN salah. Sisa percobaan: {$remainingAttempts}.", remainingAttempts: $remainingAttempts);
    }

    public static function locked(CarbonInterface $until): self
    {
        return new self(
            'PIN diblokir sementara karena terlalu banyak percobaan salah. Coba lagi pukul '.$until->format('H:i').'.',
            remainingAttempts: 0,
            lockedUntil: $until,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'remaining_attempts' => $this->remainingAttempts,
            'pin_locked_until' => $this->lockedUntil,
        ], 422);
    }
}
