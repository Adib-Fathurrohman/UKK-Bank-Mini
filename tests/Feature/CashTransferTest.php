<?php

use App\Enums\UserRoles;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Close and approve a shift in which the teller took the given deposit.
 */
function approvedShift(User $teller, User $supervisor, int $deposit): CashTransfer
{
    openShiftFor($teller);
    app(TransactionService::class)->deposit(Customer::factory()->create(), $deposit, $teller);
    $report = closeShiftFor($teller);
    app(DailyReportService::class)->approve($report, $supervisor);

    return $report->cashTransfer()->sole();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 08:00:00'));
    $this->supervisor = User::factory()->supervisor()->create();
    $this->tellerA = User::factory()->teller()->create(['full_name' => 'Teller A']);
    $this->tellerB = User::factory()->teller()->create(['full_name' => 'Teller B']);
});

test('supervisor sees the vault ledger with its total', function () {
    approvedShift($this->tellerA, $this->supervisor, 200000);
    approvedShift($this->tellerB, $this->supervisor, 50000);
    Sanctum::actingAs($this->supervisor);

    $this->getJson('/api/supervisor/cash-transfers')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('total_amount', 250000)
        ->assertJsonPath('data.0.source', 'teller_drawer')
        ->assertJsonPath('data.0.destination', 'main_vault')
        ->assertJsonPath('data.0.daily_report.teller.full_name', 'Teller B')
        ->assertJsonPath('data.0.approved_by.id', $this->supervisor->id);

    $this->getJson("/api/supervisor/cash-transfers?teller_id={$this->tellerA->id}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('total_amount', 200000);
});

test('the ledger can be filtered by date', function () {
    approvedShift($this->tellerA, $this->supervisor, 10000);
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    approvedShift($this->tellerA, $this->supervisor, 20000);
    Sanctum::actingAs($this->supervisor);

    $this->getJson('/api/supervisor/cash-transfers?from=2026-10-24')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('total_amount', 20000);
});

test('supervisor opens one transfer with its report', function () {
    $transfer = approvedShift($this->tellerA, $this->supervisor, 75000);
    Sanctum::actingAs($this->supervisor);

    $this->getJson("/api/supervisor/cash-transfers/{$transfer->id}")
        ->assertOk()
        ->assertJsonPath('data.amount', 75000)
        ->assertJsonPath('data.daily_report.report_date', '2026-10-23')
        ->assertJsonPath('data.daily_report.teller.id', $this->tellerA->id);
});

test('only supervisors can see the vault ledger', function (UserRoles $role) {
    actingAsStaff($role);

    $this->getJson('/api/supervisor/cash-transfers')->assertForbidden();
})->with([UserRoles::ADMIN, UserRoles::TELLER]);

test('a transfer cannot be changed or deleted', function () {
    $transfer = approvedShift($this->tellerA, $this->supervisor, 10000);

    expect(fn () => $transfer->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $transfer->delete())->toThrow(LogicException::class)
        ->and($transfer->fresh()->amount)->toBe(10000);
});
