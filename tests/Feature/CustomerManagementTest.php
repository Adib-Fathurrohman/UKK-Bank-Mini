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
    expect($customer->account_number)->toMatch('/^[A-Z]+-\d{4}-\d{3}$/')
        ->and(Hash::check('246810', $customer->pin))->toBeTrue();
});

test('account numbers combine major code, school year and a serial per major and year', function () {
    actingAsStaff(UserRoles::ADMIN);
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-10-27 09:00:00'));
    $rpl = Major::factory()->create(['code' => 'RPL']);
    $tkj = Major::factory()->create(['code' => 'TKJ']);

    $numbers = collect([
        [$rpl, '1'], [$rpl, '2'], [$tkj, '3'],
    ])->map(fn ($row) => $this->postJson('/api/admin/customers', customerPayload(['major_id' => $row[0]->id, 'nis' => $row[1]]))
        ->assertCreated()
        ->json('data.account_number'));

    expect($numbers->all())->toBe(['RPL-2026-001', 'RPL-2026-002', 'TKJ-2026-001']);
});

test('the school year in the account number starts in July', function () {
    actingAsStaff(UserRoles::ADMIN);
    $rpl = Major::factory()->create(['code' => 'RPL']);

    $this->travelTo(Illuminate\Support\Carbon::parse('2027-06-30 12:00:00'));
    $june = $this->postJson('/api/admin/customers', customerPayload(['major_id' => $rpl->id, 'nis' => '1']))->json('data.account_number');

    $this->travelTo(Illuminate\Support\Carbon::parse('2027-07-01 08:00:00'));
    $july = $this->postJson('/api/admin/customers', customerPayload(['major_id' => $rpl->id, 'nis' => '2']))->json('data.account_number');

    expect($june)->toBe('RPL-2026-001')->and($july)->toBe('RPL-2027-001');
});

test('serials continue after closed accounts and past 999', function () {
    actingAsStaff(UserRoles::ADMIN);
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-10-27 09:00:00'));
    $rpl = Major::factory()->create(['code' => 'RPL']);
    Customer::factory()->create(['major_id' => $rpl->id, 'account_number' => 'RPL-2026-041']);
    Customer::factory()->create(['major_id' => $rpl->id, 'account_number' => 'RPL-2026-042'])->delete();

    expect($this->postJson('/api/admin/customers', customerPayload(['major_id' => $rpl->id, 'nis' => '1']))->json('data.account_number'))
        ->toBe('RPL-2026-043');

    Customer::factory()->create(['major_id' => $rpl->id, 'account_number' => 'RPL-2026-999']);

    expect($this->postJson('/api/admin/customers', customerPayload(['major_id' => $rpl->id, 'nis' => '2']))->json('data.account_number'))
        ->toBe('RPL-2026-1000');
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
