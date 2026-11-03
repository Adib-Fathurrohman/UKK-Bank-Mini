<?php

namespace App\Http\Requests\Customer;

use App\Models\CustomerPushToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An FCM registration token of the phone the customer is using.
 */
class PushTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => [$this->isMethod('delete') ? 'nullable' : 'required', Rule::in(CustomerPushToken::PLATFORMS)],
        ];
    }
}
