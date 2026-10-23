<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Account number and balance are never editable here. Closing an account
     * (INACTIVE) goes through the deactivate endpoint so it is also soft deleted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nis' => ['sometimes', 'string', 'max:20', Rule::unique('customers', 'nis')->ignore($this->route('customer'))],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'class' => ['sometimes', 'in:X,XI,XII'],
            'major_id' => ['sometimes', 'integer', 'exists:majors,id'],
            'group' => ['sometimes', 'in:1,2,3,4'],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)->only([AccountStatus::ACTIVE, AccountStatus::SUSPENDED])],
            'pin' => ['sometimes', 'string', 'digits:6'],
        ];
    }
}
