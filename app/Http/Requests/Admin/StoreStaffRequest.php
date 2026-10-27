<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'alpha_dash', 'max:50', 'unique:users,username'],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            // Administrators can only create tellers and supervisors (FR-Usr-01).
            'role' => ['required', Rule::enum(UserRoles::class)->only([UserRoles::TELLER, UserRoles::SUPERVISOR])],
            'class' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
        ];
    }
}
