<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

    $teller = User::factory()->teller()->create();
    $this->customer = Customer::factory()->create(['balance' => 0]);
    $this->other = Customer::factory()->create(['balance' => 0]);

    $service = app(TransactionService::class);
    $this->travelTo(Carbon::parse('2026-10-20 09:00:00'));
    $service->deposit($this->customer, 100000, $teller);
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $service->withdraw($this->customer, 30000, $teller);
    $this->otherTransaction = $service->deposit($this->other, 500000, $teller);
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

    // Real requests load the customer from the database; mirror that here.
    $this->customer->refresh();
});

test('customer sees their current balance', function () {
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/customer/balance')
        ->assertOk()
        ->assertJsonPath('data.account_number', $this->customer->account_number)
        ->assertJsonPath('data.balance', 70000)
        ->assertJsonPath('data.min_balance', 10000);
});

test('customer sees their own statement, newest first, without internal details', function () {
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'withdrawal')
        ->assertJsonPath('data.0.direction', 'out')
        ->assertJsonPath('data.0.amount', 30000)
        ->assertJsonPath('data.0.balance_after', 70000)
        ->assertJsonPath('data.1.direction', 'in')
        ->assertJsonPath('data.1.balance_after', 100000)
        ->assertJsonMissingPath('data.0.teller')
        ->assertJsonMissingPath('data.0.journals')
        ->assertJsonStructure(['meta' => ['current_page', 'total'], 'links']);
});

test('the statement can be filtered by date and type', function () {
    Sanctum::actingAs($this->customer);

    $this->getJson('/api/customer/transactions?from=2026-10-21')->assertJsonCount(1, 'data');
    $this->getJson('/api/customer/transactions?to=2026-10-20')->assertJsonCount(1, 'data');
    $this->getJson('/api/customer/transactions?type=deposit')->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 100000);
});

test('customer can open one of their own transactions', function () {
    Sanctum::actingAs($this->customer);
    $id = $this->customer->transactions()->latest('id')->value('id');

    $this->getJson("/api/customer/transactions/{$id}")->assertOk()->assertJsonPath('data.id', $id);
});

test('customer cannot see another customer\'s balance or transactions', function () {
    Sanctum::actingAs($this->customer);

    $this->getJson("/api/customer/transactions/{$this->otherTransaction->id}")->assertNotFound();
    $this->getJson("/api/customer/transactions?customer_id={$this->other->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
    $this->getJson("/api/customer/balance?customer_id={$this->other->id}")->assertJsonPath('data.balance', 70000);
});

test('customer cannot reach staff, teller or audit endpoints', function (string $method, string $uri) {
    Sanctum::actingAs($this->customer);

    $this->json($method, $uri, ['account_number' => $this->customer->account_number, 'amount' => 1000])->assertForbidden();
})->with([
    ['POST', '/api/teller/deposits'],
    ['GET', '/api/audit/journals'],
    ['GET', '/api/customers'],
    ['GET', '/api/admin/staff'],
]);

test('staff cannot use the customer portal', function () {
    actingAsStaff(UserRoles::ADMIN);

    $this->getJson('/api/customer/balance')->assertForbidden();
    $this->getJson('/api/customer/transactions')->assertForbidden();
});
