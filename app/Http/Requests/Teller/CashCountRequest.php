<?php

namespace App\Http\Requests\Teller;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A cash count: how many notes/coins of each Rupiah denomination are in the drawer.
 *
 * Example: { "denominations": { "100000": 3, "50000": 2, "1000": 5 } }
 */
class CashCountRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'denominations' => ['present', 'array:'.implode(',', config('bank.denominations'))],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'denominations.array' => 'Pecahan uang yang diizinkan: '.implode(', ', config('bank.denominations')).'.',
        ];
    }

    /**
     * @return array<int|string, int>
     */
    public function counts(): array
    {
        return $this->validated('denominations');
    }
}
