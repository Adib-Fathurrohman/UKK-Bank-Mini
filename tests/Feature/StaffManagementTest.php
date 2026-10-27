<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = actingAsStaff(UserRoles::ADMIN);
});

test('admin can create a teller with identity details', function () {
    $response = $this->postJson('/api/admin/staff', [
        'username' => 'teller_baru',
        'full_name' => 'Teller Baru',
        'password' => 'rahasia123',
        'role' => 'teller',
        'class' => 'XI AK 1',
        'phone' => '081234567890',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.username', 'teller_baru')
        ->assertJsonPath('data.role', 'teller')
        ->assertJsonPath('data.class', 'XI AK 1')
        ->assertJsonPath('data.phone', '081234567890')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.password');

    $user = User::firstWhere('username', 'teller_baru');
    expect(Hash::check('rahasia123', $user->password))->toBeTrue();
});

test('admin cannot create another administrator', function () {
    $this->postJson('/api/admin/staff', [
        'username' => 'admin2',
        'full_name' => 'Admin Dua',
        'password' => 'rahasia123',
        'role' => 'admin',
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
});

test('staff usernames must be unique and phone numbers well formed', function () {
    User::factory()->create(['username' => 'teller1']);

    $this->postJson('/api/admin/staff', [
        'username' => 'teller1',
        'full_name' => 'Duplikat',
        'password' => 'rahasia123',
        'role' => 'teller',
        'phone' => 'nomor saya',
    ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'phone']);
});

test('staff list shows only active staff by default and never passwords', function () {
    User::factory()->teller()->create(['full_name' => 'Aktif']);
    User::factory()->teller()->inactive()->create(['full_name' => 'Nonaktif']);

    $response = $this->getJson('/api/staff')->assertOk();

    expect(collect($response->json('data'))->pluck('full_name'))
        ->toContain('Aktif')
        ->not->toContain('Nonaktif')
        ->and(collect($response->json('data'))->every(fn ($row) => ! array_key_exists('password', $row)))->toBeTrue();

    $this->getJson('/api/staff?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Nonaktif');
});

test('supervisor can read staff but not change them', function () {
    $teller = User::factory()->teller()->create();
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/staff')->assertOk();
    $this->getJson("/api/staff/{$teller->id}")->assertOk()->assertJsonPath('data.id', $teller->id);

    $this->postJson('/api/admin/staff', [])->assertForbidden();
    $this->patchJson("/api/admin/staff/{$teller->id}", ['full_name' => 'X'])->assertForbidden();
    $this->postJson("/api/admin/staff/{$teller->id}/block")->assertForbidden();
    $this->deleteJson("/api/admin/staff/{$teller->id}")->assertForbidden();
});

test('tellers cannot read staff', function () {
    actingAsStaff(UserRoles::TELLER);

    $this->getJson('/api/staff')->assertForbidden();
});

test('admin can update a staff member name, username, role and identity', function () {
    $teller = User::factory()->teller()->create();

    $this->patchJson("/api/admin/staff/{$teller->id}", [
        'full_name' => 'Nama Baru',
        'username' => 'nama_baru',
        'role' => 'supervisor',
        'class' => 'XII BR 2',
    ])->assertOk()
        ->assertJsonPath('data.full_name', 'Nama Baru')
        ->assertJsonPath('data.role', 'supervisor')
        ->assertJsonPath('data.class', 'XII BR 2');

    expect($teller->fresh()->role)->toBe(UserRoles::SUPERVISOR);
});

test('blocking a staff member keeps the account and revokes their tokens', function () {
    $teller = User::factory()->teller()->create();
    $teller->createToken('staff-portal');

    $this->postJson("/api/admin/staff/{$teller->id}/block")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($teller->fresh())->not->toBeNull()
        ->and($teller->fresh()->is_active)->toBeFalse()
        ->and($teller->tokens()->count())->toBe(0);
});

test('a blocked staff member can be unblocked', function () {
    $teller = User::factory()->teller()->inactive()->create();

    $this->postJson("/api/admin/staff/{$teller->id}/unblock")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('a staff member without any history can be deleted permanently', function () {
    $teller = User::factory()->teller()->create();
    $teller->createToken('staff-portal');

    $this->deleteJson("/api/admin/staff/{$teller->id}")->assertOk();

    expect(User::find($teller->id))->toBeNull()
        ->and(Laravel\Sanctum\PersonalAccessToken::count())->toBe(0);
});

test('a staff member with transaction history cannot be deleted, only blocked', function () {
    $teller = User::factory()->teller()->create();
    app(TransactionService::class)->deposit(Customer::factory()->create(), 10000, $teller);

    $this->deleteJson("/api/admin/staff/{$teller->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Blokir'));

    expect(User::find($teller->id))->not->toBeNull();
});

test('administrator accounts cannot be changed, blocked or deleted through the API', function () {
    $this->patchJson("/api/admin/staff/{$this->admin->id}", ['role' => 'teller'])->assertForbidden();
    $this->postJson("/api/admin/staff/{$this->admin->id}/block")->assertForbidden();
    $this->deleteJson("/api/admin/staff/{$this->admin->id}")->assertForbidden();

    expect($this->admin->fresh()->is_active)->toBeTrue();
});
