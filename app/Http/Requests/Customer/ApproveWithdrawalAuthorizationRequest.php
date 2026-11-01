<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's 6-digit PIN, typed in the mobile app to approve a withdrawal.
 */
class ApproveWithdrawalAuthorizationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:6'],
        ];
    }
}
