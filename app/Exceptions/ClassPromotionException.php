<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A kenaikan kelas run was refused. Rendered as {"message", "code", ...extra}.
 */
class ClassPromotionException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Class XII must be graduated first, or last year's XII and the promoted XI would mix.
     */
    public static function graduateFirst(int $open): self
    {
        return new self(
            "Masih ada {$open} rekening aktif di kelas XII. Proses kelulusan kelas XII terlebih dahulu, lalu jalankan kenaikan kelas.",
            'promotion_xii_not_empty',
            409,
            ['open_xii_count' => $open],
        );
    }

    public static function alreadyDone(string $schoolYear): self
    {
        return new self("Kenaikan kelas untuk tahun ajaran {$schoolYear} sudah dijalankan.", 'promotion_already_done');
    }

    public static function nobody(): self
    {
        return new self('Tidak ada rekening aktif di kelas X atau XI.', 'promotion_empty', 422);
    }

    /**
     * @param  array<int, int>  $missing
     * @param  array<int, int>  $unexpected
     */
    public static function selectionChanged(array $missing, array $unexpected): self
    {
        return new self(
            'Daftar rekening berubah sejak pratinjau. Muat ulang pratinjau dan periksa kembali.',
            'promotion_selection_changed',
            409,
            ['missing_customer_ids' => array_values($missing), 'unexpected_customer_ids' => array_values($unexpected)],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode, ...$this->extra], $this->status);
    }
}
