<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;

test('settings fall back to their defaults before anything is saved', function () {
    $settings = app(SettingsService::class);

    expect($settings->all())->toBe([
        'min_balance' => 10000,
        'qr_scanner_mode' => 'webcam',
    ]);
});

test('saved settings are returned with their types and record who changed them', function () {
    $admin = User::factory()->admin()->create();
    $settings = app(SettingsService::class);

    $result = $settings->update(['min_balance' => '15000', 'qr_scanner_mode' => 'usb'], $admin);

    expect($result)->toBe(['min_balance' => 15000, 'qr_scanner_mode' => 'usb'])
        ->and($settings->minBalance())->toBe(15000)
        ->and(Setting::find('min_balance')->updated_by)->toBe($admin->id);
});

test('unknown settings are rejected', function () {
    app(SettingsService::class)->get('does_not_exist');
})->throws(InvalidArgumentException::class);
