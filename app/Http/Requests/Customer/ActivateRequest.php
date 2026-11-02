<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First-time activation: the NIS the customer types and the Firebase ID token
 * of the account they signed in with.
 */
class ActivateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nis' => ['required', 'string', 'max:20'],
            'id_token' => ['required', 'string', 'max:4096'],
        ];
    }
}
