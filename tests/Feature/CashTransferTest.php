<?php

use App\Enums\CashTransferStatus;
use App\Enums\UserRoles;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\User;
use App\Services\CashTransferService;
use App\Services\TransactionService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->teller = User::factory()->teller()->create();
    $this->customer = Customer::factory()->create(['balance' => 100000]);

    // Drawer: +200.000 in, -50.000 out = 150.000
    $transactions = app(TransactionService::class);
    $transactions->deposit($this->customer, 200000, $this->teller);
    $transactions->withdraw($this->customer, 50000, $this->teller);
});

test('teller sees how much cash should be in their drawer', function () {
    Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/drawer')
        ->assertOk()
        ->assertJsonPath('data.cash_in', 200000)
        ->assertJsonPath('data.cash_out', 50000)
        ->assertJsonPath('data.drawer_balance', 150000)
        ->assertJsonPath('data.available_for_transfer', 150000);
});

test('teller requests a transfer to the main vault', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/cash-transfers', ['amount' => 100000, 'note' => 'Setor ke brankas'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.source', 'teller_drawer')
        ->assertJsonPath('data.destination', 'main_vault')
        ->assertJsonPath('data.amount', 100000);

    $this->getJson('/api/teller/drawer')
        ->assertJsonPath('data.drawer_balance', 150000)
        ->assertJsonPath('data.pending', 100000)
        ->assertJsonPath('data.available_for_transfer', 50000);
});

test('a transfer cannot exceed the cash available in the drawer', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/cash-transfers', ['amount' => 150001])->assertUnprocessable();

    $this->postJson('/api/teller/cash-transfers', ['amount' => 100000])->assertCreated();
    $this->postJson('/api/teller/cash-transfers', ['amount' => 60000])->assertUnprocessable();

    expect(CashTransfer::count())->toBe(1);
});

test('transfer amount must be positive', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/cash-transfers', ['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
});

test('supervisor approves a transfer and the approval is audited', function () {
    $transfer = app(CashTransferService::class)->request($this->teller, 100000);
    $supervisor = actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson("/api/supervisor/cash-transfers/{$transfer->id}")
        ->assertOk()
        ->assertJsonPath('drawer.drawer_balance', 150000);

    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.supervisor.id', $supervisor->id);

    expect($transfer->fresh()->reviewed_at)->not->toBeNull()
        ->and(app(CashTransferService::class)->drawer($this->teller)['drawer_balance'])->toBe(50000);
});

test('approval re-checks the drawer in case cash was paid out after the request', function () {
    $transfer = app(CashTransferService::class)->request($this->teller, 150000);
    app(TransactionService::class)->withdraw($this->customer, 20000, $this->teller);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/approve")->assertUnprocessable();

    expect($transfer->fresh()->status)->toBe(CashTransferStatus::PENDING);
});

test('rejection requires a note and returns the amount to the available cash', function () {
    $transfer = app(CashTransferService::class)->request($this->teller, 100000);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/reject", ['note' => 'Jumlah tidak sesuai'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect(app(CashTransferService::class)->drawer($this->teller)['available_for_transfer'])->toBe(150000);
});

test('a processed transfer cannot be processed or edited again', function () {
    $transfer = app(CashTransferService::class)->request($this->teller, 100000);
    app(CashTransferService::class)->approve($transfer, User::factory()->supervisor()->create());
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/reject", ['note' => 'x'])->assertUnprocessable();

    $transfer->refresh();
    expect(fn () => $transfer->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $transfer->delete())->toThrow(LogicException::class);
});

test('supervisor lists pending transfers', function () {
    app(CashTransferService::class)->request($this->teller, 10000);
    $approved = app(CashTransferService::class)->request($this->teller, 20000);
    app(CashTransferService::class)->approve($approved, User::factory()->supervisor()->create());
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/supervisor/cash-transfers?status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 10000)
        ->assertJsonPath('data.0.teller.id', $this->teller->id);
});

test('teller sees only their own transfers', function () {
    app(CashTransferService::class)->request($this->teller, 10000);
    $otherTeller = User::factory()->teller()->create();
    Sanctum::actingAs($otherTeller);

    $this->getJson('/api/teller/cash-transfers')->assertOk()->assertJsonCount(0, 'data');
});

test('tellers cannot authorize transfers and supervisors cannot request them', function () {
    $transfer = app(CashTransferService::class)->request($this->teller, 10000);

    Sanctum::actingAs($this->teller);
    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/approve")->assertForbidden();

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->postJson('/api/teller/cash-transfers', ['amount' => 1000])->assertForbidden();

    actingAsStaff(UserRoles::ADMIN);
    $this->postJson("/api/supervisor/cash-transfers/{$transfer->id}/approve")->assertForbidden();
});
