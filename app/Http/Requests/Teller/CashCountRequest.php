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
    /**
     * Rules for the optional notes of a single transaction (denomination => quantity).
     * Without them the system chooses: fewest notes for cash in, the largest notes the
     * drawer can combine for cash out.
     *
     * @return array<string, mixed>
     */
    public static function noteRules(): array
    {
        return [
            'denominations' => ['sometimes', 'array:'.implode(',', config('bank.denominations'))],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<int, int>|null
     */
    public static function notesFrom(FormRequest $request): ?array
    {
        return $request->has('denominations') ? $request->validated('denominations') : null;
    }

    public function counts(): array
    {
        return $this->validated('denominations');
    }
}
