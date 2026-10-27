<?php

use App\Enums\CashCountStage;
use App\Enums\ReportStatus;
use App\Enums\UserRoles;
use App\Models\CashCount;
use App\Models\CashTransfer;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use App\Support\Cash;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 08:00:00'));

    $this->teller = User::factory()->teller()->create();
    $this->customer = Customer::factory()->create(['account_number' => 'RPL-2026-001', 'balance' => 100000]);
    $this->transactions = app(TransactionService::class);
    $this->reports = app(DailyReportService::class);
});

/*
|--------------------------------------------------------------------------
| Opening a shift
|--------------------------------------------------------------------------
*/

test('teller opens a shift by counting the opening cash', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => ['50000' => 2, '10000' => 3, '1000' => 0]])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.report_date', '2026-10-23')
        ->assertJsonPath('data.opening_balance', 130000)
        ->assertJsonPath('data.opening_counts', [
            ['denomination' => 50000, 'quantity' => 2, 'subtotal' => 100000],
            ['denomination' => 10000, 'quantity' => 3, 'subtotal' => 30000],
        ]);

    expect(CashCount::where('stage', CashCountStage::OPENING)->count())->toBe(2);
});

test('a shift can open with an empty drawer', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => []])
        ->assertCreated()
        ->assertJsonPath('data.opening_balance', 0);
});

test('cash counts only accept Rupiah denominations and non-negative quantities', function (array $denominations) {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => $denominations])->assertUnprocessable();

    expect(DailyReport::count())->toBe(0);
})->with([
    'unknown note' => [['75000' => 1]],
    'negative quantity' => [['50000' => -1]],
]);

test('a teller cannot open two shifts on the same day', function () {
    openShiftFor($this->teller);
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => []])->assertUnprocessable();

    closeShiftFor($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => []])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'sudah ditutup'));
});

test('yesterday\'s shift must be closed before a new one opens', function () {
    $this->travelTo(Carbon::parse('2026-10-22 08:00:00'));
    openShiftFor($this->teller);
    $this->travelTo(Carbon::parse('2026-10-23 08:00:00'));
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/open', ['denominations' => []])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, '2026-10-22'));

    $this->postJson('/api/teller/shift/close', ['denominations' => []])->assertOk();
    $this->postJson('/api/teller/shift/open', ['denominations' => []])->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Running shift
|--------------------------------------------------------------------------
*/

test('the current shift shows live totals and its transactions', function () {
    openShiftFor($this->teller, 50000);
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $this->transactions->withdraw($this->customer, 10000, $this->teller);
    Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/shift')
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.opening_balance', 50000)
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.total_deposit', 30000)
        ->assertJsonPath('data.total_withdrawal', 10000)
        ->assertJsonPath('data.system_balance', 70000)
        ->assertJsonPath('data.physical_balance', null)
        ->assertJsonPath('can_transact', true)
        ->assertJsonCount(2, 'transactions');
});

test('without a running shift the current shift is empty', function () {
    Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/shift')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('can_transact', false)
        ->assertJsonPath('closed_today', false);

    openShiftFor($this->teller);
    closeShiftFor($this->teller);

    $this->getJson('/api/teller/shift')->assertJsonPath('closed_today', true);
});

test('totals only include the teller\'s own shift', function () {
    $shift = openShiftFor($this->teller);
    $this->transactions->deposit($this->customer, 30000, $this->teller);

    $other = User::factory()->teller()->create();
    openShiftFor($other);
    $this->transactions->deposit($this->customer, 99000, $other);

    expect($this->reports->summarize($shift))->toBe([
        'transaction_count' => 1,
        'total_deposit' => 30000,
        'total_withdrawal' => 0,
        'system_balance' => 30000,
    ]);
});

/*
|--------------------------------------------------------------------------
| Closing (cash reconciliation)
|--------------------------------------------------------------------------
*/

test('closing with matching cash submits the report', function () {
    openShiftFor($this->teller, 50000);
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $this->transactions->withdraw($this->customer, 10000, $this->teller);
    Sanctum::actingAs($this->teller);
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00'));

    // Rp50.000 + Rp30.000 - Rp10.000 = Rp70.000
    $this->postJson('/api/teller/shift/close', ['denominations' => ['50000' => 1, '20000' => 1]])
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.total_deposit', 30000)
        ->assertJsonPath('data.total_withdrawal', 10000)
        ->assertJsonPath('data.system_balance', 70000)
        ->assertJsonPath('data.physical_balance', 70000)
        ->assertJsonPath('data.difference', 0)
        ->assertJsonCount(2, 'data.closing_counts');

    expect(DailyReport::sole()->submitted_at->toDateTimeString())->toBe('2026-10-23 14:00:00');
});

test('closing is locked when the physical cash does not match the system', function () {
    openShiftFor($this->teller, 50000);
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/close', ['denominations' => ['50000' => 1, '20000' => 1, '5000' => 1]])
        ->assertUnprocessable()
        ->assertJsonPath('system_balance', 80000)
        ->assertJsonPath('physical_balance', 75000)
        ->assertJsonPath('difference', -5000)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'kurang Rp5.000'));

    $shift = DailyReport::sole();
    expect($shift->status)->toBe(ReportStatus::OPEN)
        ->and($shift->physical_balance)->toBeNull()
        ->and($shift->closingCounts()->count())->toBe(0);
});

test('closing needs a running shift', function () {
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/close', ['denominations' => []])->assertUnprocessable();
});

/*
|--------------------------------------------------------------------------
| Supervisor review
|--------------------------------------------------------------------------
*/

test('approval is audited and moves the counted cash to the vault', function () {
    openShiftFor($this->teller, 50000);
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $report = closeShiftFor($this->teller);
    $supervisor = actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve", ['note' => 'Sesuai'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.supervisor.id', $supervisor->id)
        ->assertJsonPath('data.supervisor_note', 'Sesuai')
        ->assertJsonPath('data.cash_transfer.amount', 80000);

    $transfer = CashTransfer::sole();
    expect($report->fresh()->reviewed_at->toDateTimeString())->toBe('2026-10-23 08:00:00')
        ->and($transfer->daily_report_id)->toBe($report->id)
        ->and($transfer->approved_by)->toBe($supervisor->id);
});

test('approving an empty drawer moves nothing to the vault', function () {
    openShiftFor($this->teller);
    $report = closeShiftFor($this->teller);

    $this->reports->approve($report, User::factory()->supervisor()->create());

    expect($report->fresh()->status)->toBe(ReportStatus::APPROVED)
        ->and(CashTransfer::count())->toBe(0);
});

test('a report can be put on hold with a note and approved later', function () {
    openShiftFor($this->teller);
    $report = closeShiftFor($this->teller);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/hold")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/hold", ['note' => 'Menunggu slip asli'])
        ->assertOk()
        ->assertJsonPath('data.status', 'on_hold');

    // The teller can still work the next day while the report is held.
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    openShiftFor($this->teller);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

test('rejection needs a note and reopens the shift for correction and re-closing', function () {
    openShiftFor($this->teller);
    $this->transactions->deposit($this->customer, 10000, $this->teller);
    $report = closeShiftFor($this->teller);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject", ['note' => 'Slip kurang satu'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    // The rejected shift is active again: the teller can transact and close it again.
    $this->transactions->deposit($this->customer, 5000, $this->teller);
    Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/shift/close', ['denominations' => Cash::breakdown(15000)])
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.total_deposit', 15000)
        ->assertJsonPath('data.supervisor_note', null);

    expect($report->closingCounts()->get()->sum(fn ($count) => $count->denomination * $count->quantity))->toBe(15000);
});

test('a held report cannot be rejected while the teller runs another shift', function () {
    openShiftFor($this->teller);
    $report = closeShiftFor($this->teller);
    $supervisor = User::factory()->supervisor()->create();
    $this->reports->hold($report, $supervisor, 'Cek ulang');

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    openShiftFor($this->teller);
    Sanctum::actingAs($supervisor);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject", ['note' => 'Salah'])->assertUnprocessable();

    expect($report->fresh()->status)->toBe(ReportStatus::ON_HOLD);
});

test('only closed reports waiting for review can be decided', function () {
    $shift = openShiftFor($this->teller);
    $supervisor = actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$shift->id}/approve")->assertUnprocessable();

    $report = closeShiftFor($this->teller);
    $this->reports->approve($report, $supervisor);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve")->assertUnprocessable();
    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject", ['note' => 'x'])->assertUnprocessable();
    $this->postJson("/api/supervisor/daily-reports/{$report->id}/hold", ['note' => 'x'])->assertUnprocessable();

    expect(CashTransfer::count())->toBe(0);
});

test('supervisor lists reports and sees counts and transactions behind one', function () {
    openShiftFor($this->teller, 20000);
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $report = closeShiftFor($this->teller);

    $other = User::factory()->teller()->create();
    openShiftFor($other);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/supervisor/daily-reports?status=submitted')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/supervisor/daily-reports?status=open')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.teller.id', $other->id);
    $this->getJson("/api/supervisor/daily-reports?teller_id={$this->teller->id}")->assertJsonCount(1, 'data');

    $this->getJson("/api/supervisor/daily-reports/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.opening_balance', 20000)
        ->assertJsonPath('data.physical_balance', 50000)
        ->assertJsonCount(1, 'data.opening_counts')
        ->assertJsonCount(1, 'data.closing_counts')
        ->assertJsonCount(1, 'transactions');
});

test('tellers see only their own reports', function () {
    openShiftFor($this->teller);
    $own = closeShiftFor($this->teller);
    $otherTeller = User::factory()->teller()->create();
    $other = openShiftFor($otherTeller);
    Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/daily-reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson("/api/teller/daily-reports/{$own->id}")->assertOk()->assertJsonCount(0, 'transactions');
    $this->getJson("/api/teller/daily-reports/{$other->id}")->assertNotFound();
});

test('tellers cannot review reports and supervisors cannot run shifts', function () {
    openShiftFor($this->teller);
    $report = closeShiftFor($this->teller);

    Sanctum::actingAs($this->teller);
    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve")->assertForbidden();

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->postJson('/api/teller/shift/open', ['denominations' => []])->assertForbidden();
    $this->postJson('/api/teller/shift/close', ['denominations' => []])->assertForbidden();
});

test('an approved report cannot be edited or deleted', function () {
    openShiftFor($this->teller);
    $report = closeShiftFor($this->teller);
    $this->reports->approve($report, User::factory()->supervisor()->create());
    $report->refresh();

    expect(fn () => $report->update(['total_deposit' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $report->delete())->toThrow(LogicException::class)
        ->and($report->fresh()->total_deposit)->toBe(0);
});
