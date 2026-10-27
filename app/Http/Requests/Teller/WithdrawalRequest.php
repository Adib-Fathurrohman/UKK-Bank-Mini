<?php

namespace App\Http\Requests\Teller;

/**
 * A withdrawal additionally carries the PIN the customer typed at the counter.
 */
class WithdrawalRequest extends StoreTransactionRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'pin' => ['required', 'string', 'digits:6'],
        ];
    }
}
