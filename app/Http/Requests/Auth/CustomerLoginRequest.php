<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CustomerLoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Either the student's NIS or their account number.
            'identifier' => ['required', 'string', 'max:50'],
            'pin' => ['required', 'string', 'digits:6'],
        ];
    }
}
