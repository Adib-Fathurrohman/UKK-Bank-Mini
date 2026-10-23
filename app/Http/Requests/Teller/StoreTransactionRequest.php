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
    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', Rule::exists('customers', 'account_number')->whereNull('deleted_at')],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
        ];
    }
}
