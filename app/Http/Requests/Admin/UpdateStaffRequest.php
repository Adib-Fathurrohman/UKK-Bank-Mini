<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Blocking and unblocking have their own endpoints.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['sometimes', 'string', 'alpha_dash', 'max:50', Rule::unique('users', 'username')->ignore($this->route('staff'))],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => ['sometimes', Rule::enum(UserRoles::class)->only([UserRoles::TELLER, UserRoles::SUPERVISOR])],
            'class' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
        ];
    }
}
