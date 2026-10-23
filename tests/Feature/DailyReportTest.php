<?php

use App\Enums\ReportStatus;
use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

    $this->teller = User::factory()->teller()->create();
    $this->customer = Customer::factory()->create(['account_number' => '1000000001', 'balance' => 100000]);
    $this->transactions = app(TransactionService::class);
});

function submitReportFor(User $teller, string $date = '2026-10-23'): DailyReport
{
    return app(DailyReportService::class)->submit($teller, Carbon::parse($date));
}

test('summary totals only the teller\'s own transactions for that day', function () {
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $this->transactions->deposit($this->customer, 20000, $this->teller);
    $this->transactions->withdraw($this->customer, 15000, $this->teller);

    $this->transactions->deposit($this->customer, 99000, User::factory()->teller()->create());

    $this->travelTo(Carbon::parse('2026-10-22 09:00:00'));
    $this->transactions->deposit($this->customer, 7000, $this->teller);
    $this->travelTo(Carbon::parse('2026-10-23 10:00:00'));

    expect(app(DailyReportService::class)->summarize($this->teller, Carbon::parse('2026-10-23')))->toBe([
        'transaction_count' => 3,
        'total_deposit' => 50000,
        'total_withdrawal' => 15000,
    ]);
});

test('teller can preview the printable cash closing draft', function () {
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $this->transactions->withdraw($this->customer, 10000, $this->teller);
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/daily-reports/preview')
        ->assertOk()
        ->assertJsonPath('data.report_date', '2026-10-23')
        ->assertJsonPath('data.transaction_count', 2)
        ->assertJsonPath('data.total_deposit', 30000)
        ->assertJsonPath('data.total_withdrawal', 10000)
        ->assertJsonPath('data.net_cash', 20000)
        ->assertJsonPath('data.counter_closed', false)
        ->assertJsonPath('data.report', null)
        ->assertJsonCount(2, 'data.transactions');

    expect(DailyReport::count())->toBe(0);
});

test('teller submits the report with totals computed by the server', function () {
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/daily-reports', ['total_deposit' => 1, 'transaction_count' => 99])
        ->assertCreated()
        ->assertJsonPath('data.report_date', '2026-10-23')
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.transaction_count', 1)
        ->assertJsonPath('data.total_deposit', 30000);
});

test('reports cannot be submitted for a future date', function () {
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/daily-reports', ['date' => '2026-10-24'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

test('a report cannot be submitted twice', function () {
    submitReportFor($this->teller);
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/daily-reports')->assertUnprocessable();

    expect(DailyReport::count())->toBe(1);
});

test('submitting the report closes the counter for that day', function () {
    submitReportFor($this->teller);
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Loket sudah ditutup'));

    expect($this->customer->fresh()->balance)->toBe(100000);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 10000])->assertCreated();
});

test('supervisor approves a submitted report and the approval is audited', function () {
    $report = submitReportFor($this->teller);
    $supervisor = actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve", ['note' => 'Sesuai'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.supervisor.id', $supervisor->id)
        ->assertJsonPath('data.supervisor_note', 'Sesuai');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::APPROVED)
        ->and($report->supervisor_id)->toBe($supervisor->id)
        ->and($report->reviewed_at->toDateTimeString())->toBe('2026-10-23 10:00:00');
});

test('rejection requires a note and reopens the counter for resubmission', function () {
    $report = submitReportFor($this->teller);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject", ['note' => 'Ada selisih kas'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    Laravel\Sanctum\Sanctum::actingAs($this->teller);
    $this->postJson('/api/teller/deposits', ['account_number' => '1000000001', 'amount' => 5000])->assertCreated();

    $this->postJson('/api/teller/daily-reports')
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.total_deposit', 5000)
        ->assertJsonPath('data.supervisor_note', null);
});

test('only submitted reports can be reviewed', function () {
    $report = submitReportFor($this->teller);
    $supervisor = User::factory()->supervisor()->create();
    app(DailyReportService::class)->approve($report, $supervisor);
    Laravel\Sanctum\Sanctum::actingAs($supervisor);

    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve")->assertUnprocessable();
    $this->postJson("/api/supervisor/daily-reports/{$report->id}/reject", ['note' => 'x'])->assertUnprocessable();

    expect($report->fresh()->status)->toBe(ReportStatus::APPROVED);
});

test('supervisor can list reports by status and see the transactions behind one', function () {
    $this->transactions->deposit($this->customer, 30000, $this->teller);
    $report = submitReportFor($this->teller);
    submitReportFor(User::factory()->teller()->create());
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/supervisor/daily-reports?status=submitted')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/supervisor/daily-reports?teller_id={$this->teller->id}")->assertJsonCount(1, 'data');

    $this->getJson("/api/supervisor/daily-reports/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.teller.id', $this->teller->id)
        ->assertJsonCount(1, 'transactions');
});

test('tellers see only their own reports', function () {
    $own = submitReportFor($this->teller);
    $other = submitReportFor(User::factory()->teller()->create());
    Laravel\Sanctum\Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/daily-reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
    $this->getJson("/api/teller/daily-reports/{$own->id}")->assertOk();
    $this->getJson("/api/teller/daily-reports/{$other->id}")->assertNotFound();
});

test('tellers cannot approve reports and supervisors cannot submit them', function () {
    $report = submitReportFor($this->teller);

    Laravel\Sanctum\Sanctum::actingAs($this->teller);
    $this->postJson("/api/supervisor/daily-reports/{$report->id}/approve")->assertForbidden();

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->postJson('/api/teller/daily-reports')->assertForbidden();
});

test('an approved report cannot be edited or deleted', function () {
    $report = submitReportFor($this->teller);
    app(DailyReportService::class)->approve($report, User::factory()->supervisor()->create());
    $report->refresh();

    expect(fn () => $report->update(['total_deposit' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $report->delete())->toThrow(LogicException::class)
        ->and($report->fresh()->total_deposit)->toBe(0);
});
