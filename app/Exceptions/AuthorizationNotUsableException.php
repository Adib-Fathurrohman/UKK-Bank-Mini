<?php

namespace App\Exceptions;

use App\Models\WithdrawalAuthorization;
use Illuminate\Http\JsonResponse;

/**
 * A withdrawal authorization cannot be used for the requested step
 * (already approved, rejected, cancelled, expired, consumed, other shift, ...).
 */
class AuthorizationNotUsableException extends BankingException
{
    public function __construct(string $message, public readonly ?WithdrawalAuthorization $authorization = null)
    {
        parent::__construct($message);
    }

    public static function status(WithdrawalAuthorization $authorization): self
    {
        return new self(
            'Permintaan penarikan ini berstatus "'.strtolower($authorization->status->label()).'" dan tidak dapat diproses.',
            $authorization,
        );
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage()];

        // The current status lets the client update its screen (e.g. "expired").
        if ($this->authorization) {
            $body['status'] = $this->authorization->status;
        }

        return response()->json($body, 422);
    }
}
