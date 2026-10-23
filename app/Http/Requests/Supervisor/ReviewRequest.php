<?php

namespace App\Http\Requests\Supervisor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or reject a submission. A rejection must explain why.
 */
class ReviewRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => [$this->routeIs('*.reject') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
