<?php

use App\Enums\AccountStatus;
use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Major;
use Illuminate\Support\Facades\Hash;

function customerPayload(array $overrides = []): array
{
    return array_merge([
        'nis' => '20269999',
        'full_name' => 'Siswa Baru',
        'class' => 'X',
        'major_id' => Major::factory()->create()->id,
        'group' => '1',
        'pin' => '246810',
    ], $overrides);
}

test('admin can open an account linked to a major', function () {
    actingAsStaff(UserRoles::ADMIN);
    $payload = customerPayload();

    $response = $this->postJson('/api/admin/customers', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.nis', '20269999')
        ->assertJsonPath('data.major.id', $payload['major_id'])
        ->assertJsonPath('data.account_status', 'active')
        ->assertJsonPath('data.balance', 0)
        ->assertJsonMissingPath('data.pin');

    $customer = Customer::firstWhere('nis', '20269999');
    expect($customer->account_number)->toMatch('/^\d{10}$/')
        ->and(Hash::check('246810', $customer->pin))->toBeTrue();
});

test('account numbers are generated sequentially and stay unique', function () {
    actingAsStaff(UserRoles::ADMIN);
    Customer::factory()->create(['account_number' => '1000000041']);
    Customer::factory()->create(['account_number' => '1000000042'])->delete();

    $first = $this->postJson('/api/admin/customers', customerPayload(['nis' => '1']))->json('data.account_number');
    $second = $this->postJson('/api/admin/customers', customerPayload(['nis' => '2']))->json('data.account_number');

    expect($first)->toBe('1000000043')->and($second)->toBe('1000000044');
});

test('the client cannot choose the account number, status or balance', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->postJson('/api/admin/customers', customerPayload([
        'account_number' => '9999999999',
        'account_status' => 'suspended',
        'balance' => 1000000,
    ]))->assertCreated()
        ->assertJsonPath('data.balance', 0)
        ->assertJsonPath('data.account_status', 'active');

    expect(Customer::where('account_number', '9999999999')->exists())->toBeFalse();
});

test('account creation is validated', function (array $overrides, string $field) {
    actingAsStaff(UserRoles::ADMIN);
    Customer::factory()->create(['nis' => '20260001']);

    $this->postJson('/api/admin/customers', customerPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'duplicate NIS' => [['nis' => '20260001'], 'nis'],
    'short PIN' => [['pin' => '123'], 'pin'],
    'non-numeric PIN' => [['pin' => 'abcdef'], 'pin'],
    'unknown major' => [['major_id' => 999], 'major_id'],
    'invalid class' => [['class' => 'XIII'], 'class'],
]);

test('admin can update account details, status and reset the PIN', function () {
    actingAsStaff(UserRoles::ADMIN);
    $customer = Customer::factory()->create(['balance' => 50000]);

    $this->patchJson("/api/admin/customers/{$customer->id}", [
        'full_name' => 'Nama Diperbarui',
        'class' => 'XI',
        'account_status' => 'suspended',
        'pin' => '111222',
    ])->assertOk()
        ->assertJsonPath('data.full_name', 'Nama Diperbarui')
        ->assertJsonPath('data.account_status', 'suspended');

    $customer->refresh();
    expect(Hash::check('111222', $customer->pin))->toBeTrue()
        ->and($customer->balance)->toBe(50000);
});

test('the update endpoint cannot close an account or change its balance', function () {
    actingAsStaff(UserRoles::ADMIN);
    $customer = Customer::factory()->create(['balance' => 50000]);

    $this->patchJson("/api/admin/customers/{$customer->id}", ['account_status' => 'inactive'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account_status');

    $this->patchJson("/api/admin/customers/{$customer->id}", ['balance' => 0])->assertOk();

    expect($customer->fresh()->balance)->toBe(50000);
});

test('deactivating a graduate marks the account inactive and soft deletes it', function () {
    actingAsStaff(UserRoles::ADMIN);
    $customer = Customer::factory()->create();
    $customer->createToken('customer-portal');

    $this->postJson("/api/admin/customers/{$customer->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.account_status', 'inactive');

    $trashed = Customer::withTrashed()->find($customer->id);
    expect(Customer::find($customer->id))->toBeNull()
        ->and($trashed->account_status)->toBe(AccountStatus::INACTIVE)
        ->and($trashed->deleted_at)->not->toBeNull()
        ->and($trashed->tokens()->count())->toBe(0);
});

test('supervisor can read accounts but not change them', function () {
    actingAsStaff(UserRoles::SUPERVISOR);
    $customer = Customer::factory()->create();

    $this->getJson('/api/customers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/customers/{$customer->id}")->assertOk();

    $this->postJson('/api/admin/customers', customerPayload())->assertForbidden();
    $this->patchJson("/api/admin/customers/{$customer->id}", ['full_name' => 'X'])->assertForbidden();
    $this->postJson("/api/admin/customers/{$customer->id}/deactivate")->assertForbidden();
});

test('the account list can be searched, filtered and include closed accounts', function () {
    actingAsStaff(UserRoles::ADMIN);
    Customer::factory()->create(['full_name' => 'Budi Santoso', 'class' => 'X']);
    Customer::factory()->create(['full_name' => 'Citra Lestari', 'class' => 'XII']);
    Customer::factory()->create(['full_name' => 'Lulusan Lama'])->delete();

    $this->getJson('/api/customers?search=budi')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Budi Santoso');
    $this->getJson('/api/customers?class=XII')->assertJsonCount(1, 'data');
    $this->getJson('/api/customers')->assertJsonCount(2, 'data');
    $this->getJson('/api/customers?trashed=only')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Lulusan Lama');
});

test('a closed account can still be viewed for audit purposes', function () {
    actingAsStaff(UserRoles::SUPERVISOR);
    $customer = Customer::factory()->create();
    $customer->delete();

    $this->getJson("/api/customers/{$customer->id}")->assertOk()->assertJsonPath('data.id', $customer->id);
});

test('tellers cannot browse the account list', function () {
    actingAsStaff(UserRoles::TELLER);

    $this->getJson('/api/customers')->assertForbidden();
});

test('teller can look up a customer by account number or NIS', function () {
    actingAsStaff(UserRoles::TELLER);
    $customer = Customer::factory()->create(['account_number' => '1000000077', 'nis' => '20260077', 'balance' => 25000]);

    $this->getJson('/api/teller/customers/lookup?identifier=1000000077')
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id)
        ->assertJsonPath('data.balance', 25000)
        ->assertJsonMissingPath('data.pin');

    $this->getJson('/api/teller/customers/lookup?identifier=20260077')->assertOk()->assertJsonPath('data.id', $customer->id);
    $this->getJson('/api/teller/customers/lookup?identifier=0000')->assertNotFound();
});

test('staff can list majors', function () {
    actingAsStaff(UserRoles::ADMIN);
    Major::factory()->count(2)->create();

    $this->getJson('/api/majors')->assertOk()->assertJsonCount(2, 'data');
});

test('missing records return a generic 404 without model details', function () {
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/customers/999999')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Data tidak ditemukan.']);
});
