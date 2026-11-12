<?php

namespace App\Exceptions;

use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;

/**
 * The notes and coins given for a transaction do not fit: they do not add up
 * to the amount, or the drawer does not hold them.
 */
class CashBreakdownException extends BankingException
{
    /**
     * @param  array<int, array{requested: int, available: int}>  $shortages
     */
    public function __construct(string $message, public readonly string $errorCode, public readonly array $shortages = [])
    {
        parent::__construct($message);
    }

    public static function totalMismatch(int $amount, int $total): self
    {
        return new self(
            'Jumlah pecahan '.Rupiah::format($total).' tidak sama dengan nominal transaksi '.Rupiah::format($amount).'.',
            'cash_breakdown_mismatch',
        );
    }

    /**
     * @param  array<int, array{requested: int, available: int}>  $shortages
     */
    public static function notInDrawer(array $shortages): self
    {
        $parts = [];
        foreach ($shortages as $denomination => $shortage) {
            $parts[] = Rupiah::format($denomination).' diminta '.$shortage['requested'].', tersedia '.$shortage['available'];
        }

        return new self('Pecahan tidak tersedia di laci: '.implode('; ', $parts).'.', 'drawer_insufficient', $shortages);
    }

    /**
     * No combination of the notes in the drawer adds up to the amount.
     */
    public static function cannotPay(int $amount): self
    {
        return new self(
            'Uang di laci tidak dapat membayar '.Rupiah::format($amount).' dengan pecahan yang tersedia. Pilih pecahan secara manual atau kurangi nominal.',
            'drawer_insufficient',
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'shortages' => $this->shortages ?: null,
        ], fn ($value) => $value !== null), 422);
    }
}
