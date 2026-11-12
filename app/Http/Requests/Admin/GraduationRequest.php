<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which class graduates: class required, major and group optional. For the
 * execution also the account ids the administrator confirmed in the preview.
 */
class GraduationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'class' => ['required', 'in:X,XI,XII'],
            'major_id' => ['nullable', 'integer', 'exists:majors,id'],
            'group' => ['nullable', 'in:1,2,3,4'],
        ];

        if ($this->isMethod('post')) {
            $rules += [
                'customer_ids' => ['required', 'array', 'min:1', 'max:2000'],
                'customer_ids.*' => ['integer', 'distinct'],
                'note' => ['nullable', 'string', 'max:255'],
            ];
        }

        return $rules;
    }

    /**
     * @return array{class: string, major_id: int|null, group: string|null}
     */
    public function filters(): array
    {
        return [
            'class' => $this->validated('class'),
            'major_id' => $this->validated('major_id') !== null ? (int) $this->validated('major_id') : null,
            'group' => $this->validated('group'),
        ];
    }
}
