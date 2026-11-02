<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's first PIN (or a new one after an administrator reset),
 * together with a fresh Firebase ID token as re-authentication.
 */
class SetUpPinRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'pin' => ['required', 'string', 'digits:6', 'confirmed'],
        ];
    }
}
