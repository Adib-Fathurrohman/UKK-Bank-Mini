<?php

namespace App\Exceptions;

use App\Models\PinResetRequest;
use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * A forgotten-PIN request step was refused. Rendered as {"message", "code", ...extra}.
 */
class PinResetException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function alreadyOpen(): self
    {
        return new self('Sudah ada permintaan reset PIN yang belum selesai.', 'pin_reset_already_requested');
    }

    public static function notApproved(): self
    {
        return new self('Permintaan reset PIN belum disetujui admin.', 'pin_reset_not_approved');
    }

    public static function notReady(PinResetRequest $request): self
    {
        return new self(
            'Masa tunggu reset PIN belum selesai. PIN baru dapat dibuat setelah waktu yang ditentukan.',
            'pin_reset_not_ready',
            409,
            ['available_at' => $request->available_at],
        );
    }

    public static function notUsable(PinResetRequest $request): self
    {
        return new self(
            'Permintaan reset PIN ini sudah tidak dapat digunakan ('.strtolower($request->status->label()).').',
            'pin_reset_not_usable',
            409,
            ['status' => $request->status->value],
        );
    }

    /**
     * While a request is open, withdrawals are paused: the old PIN may be known to someone else.
     */
    public static function withdrawalsPaused(): self
    {
        return new self(
            'Penarikan ditunda karena ada permintaan reset PIN yang belum selesai pada rekening ini. Batalkan permintaan di aplikasi atau selesaikan reset PIN terlebih dahulu.',
            'pin_reset_in_progress',
            422,
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
