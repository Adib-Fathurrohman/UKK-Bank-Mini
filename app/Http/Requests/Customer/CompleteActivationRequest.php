<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The invitation token plus the Firebase ID token of the account the customer
 * signed in with (Google, or a new email/password account with a verified email).
 */
class CompleteActivationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'id_token' => ['required', 'string', 'max:4096'],
        ];
    }
}
