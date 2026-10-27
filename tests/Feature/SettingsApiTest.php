<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\TransactionService;

test('every staff role can read the settings', function (UserRoles $role) {
    actingAsStaff($role);

    $this->getJson('/api/settings')
        ->assertOk()
        ->assertJsonPath('data.min_balance', 10000)
        ->assertJsonPath('data.qr_scanner_mode', 'webcam');
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR, UserRoles::TELLER]);

test('customers cannot read the settings', function () {
    actingAsCustomer();

    $this->getJson('/api/settings')->assertForbidden();
});

test('admin can change the minimum balance and scanner mode', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->patchJson('/api/admin/settings', ['min_balance' => 20000, 'qr_scanner_mode' => 'usb'])
        ->assertOk()
        ->assertJsonPath('data.min_balance', 20000)
        ->assertJsonPath('data.qr_scanner_mode', 'usb');

    expect(app(SettingsService::class)->minBalance())->toBe(20000);
});

test('settings are validated', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->patchJson('/api/admin/settings', ['min_balance' => -1, 'qr_scanner_mode' => 'bluetooth'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_balance', 'qr_scanner_mode']);
});

test('only administrators can change settings', function (UserRoles $role) {
    actingAsStaff($role);

    $this->patchJson('/api/admin/settings', ['min_balance' => 0])->assertForbidden();
})->with([UserRoles::SUPERVISOR, UserRoles::TELLER]);

test('withdrawals use the minimum balance set by the administrator', function () {
    app(SettingsService::class)->update(['min_balance' => 30000], User::factory()->admin()->create());
    $teller = User::factory()->teller()->create();
    openShiftFor($teller);
    $customer = Customer::factory()->create(['balance' => 50000]);

    expect(fn () => app(TransactionService::class)->withdraw($customer, 25000, $teller, '123456'))
        ->toThrow(App\Exceptions\InsufficientBalanceException::class);
});
