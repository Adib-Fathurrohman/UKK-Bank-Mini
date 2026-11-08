<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The new PIN of an approved reset, with a fresh Firebase ID token as re-authentication.
 */
class CompletePinResetRequest extends FormRequest
{
    /**
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
