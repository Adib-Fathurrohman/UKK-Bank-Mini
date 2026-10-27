<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Global configuration set by the administrator (soal 1a: "Konfigurasi Sistem global").
 *
 * Every key has a type, validation rules and a default, so the system works
 * before an administrator has saved anything.
 */
class SettingsService
{
    public const QR_SCANNER_MODES = ['webcam', 'usb', 'disabled'];

    /**
     * @return array<string, array{type: string, rules: array<int, string>, default: mixed}>
     */
    public function definitions(): array
    {
        return [
            // Balance that must remain after a withdrawal (Rupiah).
            'min_balance' => [
                'type' => 'int',
                'rules' => ['integer', 'min:0', 'max:1000000'],
                'default' => (int) config('bank.min_balance'),
            ],
            // How the teller counter reads customer QR cards: camera, a USB
            // scanner that types like a keyboard, or manual entry only.
            'qr_scanner_mode' => [
                'type' => 'string',
                'rules' => ['string', 'in:'.implode(',', self::QR_SCANNER_MODES)],
                'default' => 'webcam',
            ],
        ];
    }

    public function get(string $key): mixed
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");

        $value = Setting::find($key)?->value;

        return $value === null ? $definition['default'] : $this->cast($value, $definition['type']);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return collect($this->definitions())->keys()->mapWithKeys(fn ($key) => [$key => $this->get($key)])->all();
    }

    /**
     * @param  array<string, mixed>  $values  Already validated against definitions().
     */
    public function update(array $values, User $admin): array
    {
        DB::transaction(function () use ($values, $admin) {
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $value, 'updated_by' => $admin->id],
                );
            }
        });

        return $this->all();
    }

    /**
     * Validation rules for a partial update of the settings.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return collect($this->definitions())->map(fn ($definition) => ['sometimes', ...$definition['rules']])->all();
    }

    public function minBalance(): int
    {
        return $this->get('min_balance');
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => $value,
        };
    }
}
