<?php

use App\Enums\UserRoles;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'staff:supervisor'])
        ->get('/api/_test/supervisor-only', fn () => response()->json(['ok' => true]));
});

test('a staff member with an allowed role passes', function () {
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/_test/supervisor-only')->assertOk();
});

test('a staff member with another role gets 403', function (UserRoles $role) {
    actingAsStaff($role);

    $this->getJson('/api/_test/supervisor-only')->assertForbidden();
})->with([UserRoles::ADMIN, UserRoles::TELLER]);

test('a deactivated staff member gets 403 even with a valid token', function () {
    actingAsStaff(UserRoles::SUPERVISOR, ['is_active' => false]);

    $this->getJson('/api/_test/supervisor-only')->assertForbidden();
});

test('a customer cannot use staff endpoints', function () {
    actingAsCustomer();

    $this->getJson('/api/_test/supervisor-only')->assertForbidden();
    $this->getJson('/api/staff/me')->assertForbidden();
});

test('staff cannot use customer endpoints', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->getJson('/api/customer/me')->assertForbidden();
});

test('an inactive customer with a leftover token gets 403', function () {
    actingAsCustomer(['account_status' => 'inactive']);

    $this->getJson('/api/customer/me')->assertForbidden();
});

test('requests without a token get 401', function () {
    $this->getJson('/api/_test/supervisor-only')->assertUnauthorized();
});
