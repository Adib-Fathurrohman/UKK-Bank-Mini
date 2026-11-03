<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Support\Email;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Account number and balance are never editable here. Closing an account
     * (INACTIVE) goes through the deactivate endpoint so it is also soft deleted.
     * The email is optional contact information. The PIN is never set here:
     * POST /admin/customers/{id}/pin-reset hands out a new temporary PIN.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nis' => ['sometimes', 'string', 'max:20', Rule::unique('customers', 'nis')->ignore($this->route('customer'))],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer'))],
            'class' => ['sometimes', 'in:X,XI,XII'],
            'major_id' => ['sometimes', 'integer', 'exists:majors,id'],
            'group' => ['sometimes', 'in:1,2,3,4'],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)->only([AccountStatus::ACTIVE, AccountStatus::SUSPENDED])],
            'pin' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['pin.prohibited' => 'PIN tidak dapat diubah di sini. Gunakan reset PIN untuk membuat PIN sementara baru.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Email::normalize($this->input('email'))]);
        }
    }
}
