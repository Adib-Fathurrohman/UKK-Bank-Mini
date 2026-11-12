<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A graduation batch was refused. Rendered as {"message", "code", ...extra}.
 */
class GraduationException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function nobody(): self
    {
        return new self('Tidak ada rekening aktif pada kelas yang dipilih.', 'graduation_empty', 422);
    }

    /**
     * The accounts confirmed in the preview are no longer exactly the class
     * (an account was opened, closed or moved in between).
     *
     * @param  array<int, int>  $missing  in the class now, not confirmed
     * @param  array<int, int>  $unexpected  confirmed, no longer in the class
     */
    public static function selectionChanged(array $missing, array $unexpected): self
    {
        return new self(
            'Daftar rekening berubah sejak pratinjau. Muat ulang pratinjau dan periksa kembali sebelum memproses kelulusan.',
            'graduation_selection_changed',
            409,
            ['missing_customer_ids' => array_values($missing), 'unexpected_customer_ids' => array_values($unexpected)],
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            ...$this->extra,
        ], $this->status);
    }
}
