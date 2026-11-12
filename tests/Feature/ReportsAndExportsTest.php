<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\User;
use App\Services\DailyReportService;
use App\Services\ReportService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    $this->teller = User::factory()->teller()->create(['full_name' => 'Rina Teller']);
    $this->supervisor = User::factory()->supervisor()->create();
    $this->customer = Customer::factory()->create(['account_number' => 'RPL-2026-001', 'full_name' => 'Budi Santoso', 'balance' => 0]);
    $this->other = Customer::factory()->create(['account_number' => 'TKJ-2026-001', 'full_name' => 'Citra Lestari', 'balance' => 0]);
    $this->transactions = app(TransactionService::class);
    openShiftFor($this->teller);
});

function assertPdf(TestResponse $response, string $filename): void
{
    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload($filename);

    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
}

/*
|--------------------------------------------------------------------------
| Audit filters
|--------------------------------------------------------------------------
*/

test('the audit log filters by date and time range, teller name and customer', function () {
    $otherTeller = User::factory()->teller()->create(['full_name' => 'Dodi Teller']);
    openShiftFor($otherTeller);

    $this->transactions->deposit($this->customer, 10000, $this->teller);
    $this->travelTo(Carbon::parse('2026-10-23 11:30:00'));
    $this->transactions->deposit($this->other, 20000, $otherTeller);
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->getJson('/api/audit/transactions?from='.urlencode('2026-10-23 10:00'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 20000);
    $this->getJson('/api/audit/transactions?to='.urlencode('2026-10-23 09:30'))->assertJsonCount(1, 'data');
    $this->getJson('/api/audit/transactions?teller=rina')->assertJsonCount(1, 'data')->assertJsonPath('data.0.teller.full_name', 'Rina Teller');
    $this->getJson('/api/audit/transactions?customer=TKJ-2026')->assertJsonCount(1, 'data');
    $this->getJson('/api/audit/transactions?customer=budi')->assertJsonCount(1, 'data');
});

test('journal rows show teller and customer and can be filtered the same way', function () {
    $this->transactions->deposit($this->customer, 10000, $this->teller);
    $this->transactions->deposit($this->other, 20000, $this->teller);
    actingAsStaff(UserRoles::ADMIN);

    $this->getJson('/api/audit/journals?customer=citra')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.transaction.customer.account_number', 'TKJ-2026-001')
        ->assertJsonPath('data.0.transaction.teller.full_name', 'Rina Teller');

    $this->getJson('/api/audit/journals/summary?customer=citra')
        ->assertJsonPath('data.total_debit', 20000)
        ->assertJsonPath('data.is_balanced', true);
});

test('transactions carry their amount in words for receipts', function () {
    $this->transactions->deposit($this->customer, 125000, $this->teller);
    Sanctum::actingAs($this->teller);

    $this->getJson('/api/teller/transactions')
        ->assertJsonPath('data.0.amount_in_words', 'seratus dua puluh lima ribu rupiah');
});

/*
|--------------------------------------------------------------------------
| PDF exports
|--------------------------------------------------------------------------
*/

test('admin and supervisor export the journal and the audit trail as PDF', function (UserRoles $role) {
    $this->transactions->deposit($this->customer, 10000, $this->teller);
    actingAsStaff($role);

    assertPdf($this->get('/api/audit/journals/export?from=2026-10-01&to=2026-10-31'), 'jurnal-akuntansi.pdf');
    assertPdf($this->get('/api/audit/transactions/export'), 'jejak-audit-transaksi.pdf');
})->with([UserRoles::ADMIN, UserRoles::SUPERVISOR]);

test('the journal report lists rows, totals and the balance check', function () {
    $this->transactions->deposit($this->customer, 10000, $this->teller);

    $html = view('pdf.journal', [
        'journals' => App\Models\AccountingJournal::with(['transaction.customer', 'transaction.teller'])->get(),
        'truncated' => false,
        'summary' => ['accounts' => collect([
            ['account_code' => App\Enums\AccountCode::CASH, 'account_name' => 'Kas', 'total_debit' => 10000, 'total_credit' => 0],
            ['account_code' => App\Enums\AccountCode::CUSTOMER_SAVINGS, 'account_name' => 'Tabungan Nasabah', 'total_debit' => 0, 'total_credit' => 10000],
        ]), 'total_debit' => 10000, 'total_credit' => 10000, 'is_balanced' => true],
        'filters' => ['from' => '2026-10-01'],
    ])->render();

    expect($html)->toContain('Laporan Jurnal Akuntansi')
        ->toContain('Rina Teller')
        ->toContain('RPL-2026-001')
        ->toContain('Seimbang')
        ->toContain('Rp10.000');
});

test('the teller prints a receipt for their own transaction only', function () {
    $deposit = $this->transactions->deposit($this->customer, 50000, $this->teller);
    $otherTeller = User::factory()->teller()->create();
    openShiftFor($otherTeller);
    $foreign = $this->transactions->deposit($this->customer, 1000, $otherTeller);
    Sanctum::actingAs($this->teller);

    assertPdf($this->get("/api/teller/transactions/{$deposit->id}/receipt"), "struk-{$deposit->id}.pdf");
    $this->getJson("/api/teller/transactions/{$foreign->id}/receipt")->assertNotFound();

    $html = view('pdf.receipt', ['transaction' => $deposit->load(['customer', 'teller', 'authorizer'])])->render();
    expect($html)->toContain('BUKTI SETOR TUNAI')
        ->toContain('lima puluh ribu rupiah')
        ->toContain('Rina Teller');
});

test('the daily report prints only after the shift closed with matching cash', function () {
    $this->transactions->deposit($this->customer, 50000, $this->teller);
    $shift = app(DailyReportService::class)->activeShift($this->teller);
    Sanctum::actingAs($this->teller);

    $this->getJson("/api/teller/daily-reports/{$shift->id}/export")
        ->assertUnprocessable()
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'setelah shift ditutup'));

    $report = closeShiftFor($this->teller);

    assertPdf($this->get("/api/teller/daily-reports/{$report->id}/export"), 'laporan-harian-2026-10-23-rina-teller.pdf');

    Sanctum::actingAs($this->supervisor);
    assertPdf($this->get("/api/supervisor/daily-reports/{$report->id}/export"), 'laporan-harian-2026-10-23-rina-teller.pdf');

    $html = view('pdf.daily-report', [
        'report' => $report->load(['teller', 'supervisor', 'openingCounts', 'closingCounts']),
        'transactions' => $report->transactions()->with('customer')->get(),
    ])->render();
    expect($html)->toContain('Jumat, 23 Oktober 2026')
        ->toContain('Saldo akhir sistem')
        ->toContain('Rp50.000')
        ->toContain('lima puluh ribu rupiah')
        ->toContain('1 lembar');
});

test('tellers cannot print another teller\'s daily report', function () {
    $otherTeller = User::factory()->teller()->create();
    openShiftFor($otherTeller);
    $report = closeShiftFor($otherTeller);
    Sanctum::actingAs($this->teller);

    $this->getJson("/api/teller/daily-reports/{$report->id}/export")->assertNotFound();
});

test('a customer statement has the opening balance, every line and the closing balance', function () {
    $this->transactions->deposit($this->customer, 100000, $this->teller);
    closeShiftFor($this->teller);
    $this->travelTo(Carbon::parse('2026-10-24 09:00:00'));
    openShiftFor($this->teller, cashFloat());
    $this->transactions->withdraw($this->customer, 30000, $this->teller, '123456');
    $this->transactions->deposit($this->customer, 5000, $this->teller);

    $statement = app(ReportService::class)->statement(
        $this->customer,
        Carbon::parse('2026-10-24')->startOfDay(),
        Carbon::parse('2026-10-24')->endOfDay(),
    );

    expect($statement['opening_balance'])->toBe(100000)
        ->and($statement['lines'])->toHaveCount(2)
        ->and($statement['total_in'])->toBe(5000)
        ->and($statement['total_out'])->toBe(30000)
        ->and($statement['closing_balance'])->toBe(75000);
});

test('admin, supervisor and the customer themself can export a statement', function () {
    $this->transactions->deposit($this->customer, 100000, $this->teller);

    actingAsStaff(UserRoles::SUPERVISOR);
    assertPdf($this->get("/api/customers/{$this->customer->id}/statement?from=2026-10-01"), 'mutasi-RPL-2026-001.pdf');

    Sanctum::actingAs($this->customer);
    assertPdf($this->get('/api/customer/statement/export'), 'mutasi-RPL-2026-001.pdf');

    Sanctum::actingAs($this->teller);
    $this->get("/api/customers/{$this->customer->id}/statement")->assertForbidden();
});

test('the annual report covers one school year per account and per product', function () {
    // Before the school year: Budi has Rp100.000.
    $this->travelTo(Carbon::parse('2026-06-20 09:00:00'));
    $juneTeller = User::factory()->teller()->create();
    openShiftFor($juneTeller);
    $this->transactions->deposit($this->customer, 100000, $juneTeller);
    closeShiftFor($juneTeller);

    // During 2026/2027.
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    // A teller whose drawer holds notes to pay the withdrawal with.
    $octoberTeller = User::factory()->teller()->create();
    openShiftFor($octoberTeller, cashFloat());
    $this->transactions->withdraw($this->customer, 20000, $octoberTeller, '123456');
    $deposit = $this->transactions->deposit($this->other, 70000, $this->teller);
    $this->transactions->correct($deposit, $this->teller, $this->supervisor, 'Salah ketik');
    $this->transactions->deposit($this->other, 7000, $this->teller);

    $annual = app(ReportService::class)->annual(2026);
    $rows = $annual['rows']->keyBy(fn ($row) => $row['customer']->account_number);

    expect($annual['label'])->toBe('2026/2027')
        ->and($rows['RPL-2026-001'])->toMatchArray(['opening_balance' => 100000, 'deposits' => 0, 'withdrawals' => 20000, 'closing_balance' => 80000])
        ->and($rows['TKJ-2026-001'])->toMatchArray(['opening_balance' => 0, 'deposits' => 7000, 'withdrawals' => 0, 'closing_balance' => 7000])
        ->and($annual['totals']['closing_balance'])->toBe(87000)
        ->and($annual['totals']['closing_balance'])->toBe((int) Customer::sum('balance'))
        ->and($annual['products'][0])->toMatchArray(['account_code' => '201', 'accounts' => 2, 'closing_balance' => 87000]);
});

test('admin and supervisor download the annual report', function () {
    actingAsStaff(UserRoles::ADMIN);
    assertPdf($this->get('/api/reports/annual?school_year=2026'), 'laporan-tahunan-2026-2027.pdf');

    // Defaults to the current school year.
    assertPdf($this->get('/api/reports/annual'), 'laporan-tahunan-2026-2027.pdf');

    Sanctum::actingAs($this->teller);
    $this->get('/api/reports/annual')->assertForbidden();
});
