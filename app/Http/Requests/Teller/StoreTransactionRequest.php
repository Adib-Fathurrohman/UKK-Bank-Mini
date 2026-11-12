<?php

namespace App\Http\Requests\Teller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'denominations.array' => 'Pecahan uang yang diizinkan: '.implode(', ', config('bank.denominations')).'.',
        ];
    }

    /**
     * Notes received (deposit) or paid out (withdrawal), or null to let the system choose.
     *
     * @return array<int, int>|null
     */
    public function notes(): ?array
    {
        return CashCountRequest::notesFrom($this);
    }

    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', Rule::exists('customers', 'account_number')->whereNull('deleted_at')],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            ...CashCountRequest::noteRules(),
        ];
    }
}
