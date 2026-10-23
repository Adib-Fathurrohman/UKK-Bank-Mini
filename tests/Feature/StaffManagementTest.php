<?php

use App\Enums\UserRoles;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->admin = actingAsStaff(UserRoles::ADMIN);
});

test('admin can create a teller', function () {
    $response = $this->postJson('/api/admin/staff', [
        'username' => 'teller_baru',
        'full_name' => 'Teller Baru',
        'password' => 'rahasia123',
        'role' => 'teller',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.username', 'teller_baru')
        ->assertJsonPath('data.role', 'teller')
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

test('staff usernames must be unique', function () {
    User::factory()->create(['username' => 'teller1']);

    $this->postJson('/api/admin/staff', [
        'username' => 'teller1',
        'full_name' => 'Duplikat',
        'password' => 'rahasia123',
        'role' => 'teller',
    ])->assertUnprocessable()->assertJsonValidationErrors('username');
});

test('staff list shows only active staff by default and never passwords', function () {
    User::factory()->teller()->create(['full_name' => 'Aktif']);
    User::factory()->teller()->inactive()->create(['full_name' => 'Nonaktif']);

    $response = $this->getJson('/api/admin/staff')->assertOk();

    expect(collect($response->json('data'))->pluck('full_name'))
        ->toContain('Aktif')
        ->not->toContain('Nonaktif')
        ->and(collect($response->json('data'))->every(fn ($row) => ! array_key_exists('password', $row)))->toBeTrue();

    $this->getJson('/api/admin/staff?status=inactive')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Nonaktif');
});

test('admin can update a staff member name, username and role', function () {
    $teller = User::factory()->teller()->create();

    $this->patchJson("/api/admin/staff/{$teller->id}", [
        'full_name' => 'Nama Baru',
        'username' => 'nama_baru',
        'role' => 'supervisor',
    ])->assertOk()
        ->assertJsonPath('data.full_name', 'Nama Baru')
        ->assertJsonPath('data.role', 'supervisor');

    expect($teller->fresh()->role)->toBe(UserRoles::SUPERVISOR);
});

test('deleting a staff member deactivates them and revokes their tokens', function () {
    $teller = User::factory()->teller()->create();
    $teller->createToken('staff-portal');

    $this->deleteJson("/api/admin/staff/{$teller->id}")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($teller->fresh())->not->toBeNull()
        ->and($teller->fresh()->is_active)->toBeFalse()
        ->and($teller->tokens()->count())->toBe(0);
});

test('a deactivated staff member can be reactivated', function () {
    $teller = User::factory()->teller()->inactive()->create();

    $this->patchJson("/api/admin/staff/{$teller->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('administrator accounts cannot be changed or deactivated through the API', function () {
    $this->patchJson("/api/admin/staff/{$this->admin->id}", ['role' => 'teller'])->assertForbidden();
    $this->deleteJson("/api/admin/staff/{$this->admin->id}")->assertForbidden();

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

test('only administrators can manage staff', function (UserRoles $role) {
    actingAsStaff($role);

    $this->getJson('/api/admin/staff')->assertForbidden();
    $this->postJson('/api/admin/staff', [])->assertForbidden();
})->with([UserRoles::SUPERVISOR, UserRoles::TELLER]);
