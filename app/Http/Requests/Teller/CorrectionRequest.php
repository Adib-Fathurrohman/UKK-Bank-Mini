<?php

namespace App\Http\Requests\Teller;

use App\Enums\UserRoles;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

/**
 * A correction needs a reason and a supervisor's username and password
 * (the "kode sandi koreksi"), so every correction records who authorized it.
 */
class CorrectionRequest extends FormRequest
{
    private ?User $supervisor = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<int, int>|null
     */
    public function notes(): ?array
    {
        return CashCountRequest::notesFrom($this);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'supervisor_username' => ['required', 'string'],
            'supervisor_password' => ['required', 'string'],
            // Notes moved back; default: the same notes as the original transaction.
            ...CashCountRequest::noteRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $supervisor = User::where('username', $this->input('supervisor_username'))->first();

                if (! $supervisor
                    || $supervisor->role !== UserRoles::SUPERVISOR
                    || ! $supervisor->is_active
                    || ! Hash::check((string) $this->input('supervisor_password'), $supervisor->password)) {
                    $validator->errors()->add('supervisor_password', 'Otorisasi supervisor gagal. Periksa username dan password supervisor.');

                    return;
                }

                $this->supervisor = $supervisor;
            },
        ];
    }

    public function supervisor(): User
    {
        return $this->supervisor;
    }
}
