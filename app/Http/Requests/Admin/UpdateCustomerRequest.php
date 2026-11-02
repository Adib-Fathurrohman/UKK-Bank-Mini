<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Support\Email;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Account number and balance are never editable here. Closing an account
     * (INACTIVE) goes through the deactivate endpoint so it is also soft deleted.
     * The email can be set or changed only while the account is not activated:
     * after activation it belongs to the customer's Firebase account.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nis' => ['sometimes', 'string', 'max:20', Rule::unique('customers', 'nis')->ignore($this->route('customer'))],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255', Rule::unique('customers', 'email')->ignore($this->route('customer'))],
            'class' => ['sometimes', 'in:X,XI,XII'],
            'major_id' => ['sometimes', 'integer', 'exists:majors,id'],
            'group' => ['sometimes', 'in:1,2,3,4'],
            'account_status' => ['sometimes', Rule::enum(AccountStatus::class)->only([AccountStatus::ACTIVE, AccountStatus::SUSPENDED])],
            // Only the customer sets a PIN; POST /admin/customers/{id}/pin-reset removes it.
            'pin' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $customer = $this->route('customer');

                if ($this->has('email') && $customer->isActivated() && $this->input('email') !== $customer->email) {
                    $validator->errors()->add('email', 'Email tidak dapat diubah setelah rekening diaktifkan.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['pin.prohibited' => 'PIN dibuat oleh nasabah di aplikasi Bank Mini. Gunakan reset PIN jika nasabah lupa PIN.'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Email::normalize($this->input('email'))]);
        }
    }
}
