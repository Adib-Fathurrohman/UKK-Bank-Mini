<?php

namespace App\Http\Requests\Teller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Completing a withdrawal the customer approved in the app: optionally the
 * notes paid out of the drawer.
 */
class CompleteWithdrawalAuthorizationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CashCountRequest::noteRules();
    }

    /**
     * @return array<int, int>|null
     */
    public function notes(): ?array
    {
        return CashCountRequest::notesFrom($this);
    }
}
