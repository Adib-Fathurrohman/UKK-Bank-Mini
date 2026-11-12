<?php

use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Transaction;
use App\Models\TransactionCash;
use App\Models\User;
use App\Services\CashDrawerService;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| The teller's drawer per denomination: opening count + notes received - notes
| paid out. Deposits bring in exactly the amount, withdrawals can only pay out
| notes the drawer holds, and closing must match the drawer note by note.
*/

beforeEach(function () {
    allowTerminalWithdrawals('both');
    $this->travelTo(Carbon::parse('2026-11-12 09:00:00'));
    $this->teller = User::factory()->teller()->create();
    $this->supervisor = User::factory()->supervisor()->create(['username' => 'spv', 'password' => 'rahasia123']);
    $this->customer = Customer::factory()->create(['account_number' => 'RPL-2026-001', 'balance' => 500000]);
    // Rp200.000 in the drawer: 1 x 100.000, 1 x 50.000, 2 x 20.000, 1 x 10.000.
    $this->shift = openShiftFor($this->teller, [100000 => 1, 50000 => 1, 20000 => 2, 10000 => 1]);
    Sanctum::actingAs($this->teller);
});

function drawer(): array
{
    return app(CashDrawerService::class)->contents(DailyReport::where('teller_id', test()->teller->id)->latest('id')->first());
}

function depositNotes(int $amount, ?array $notes = null)
{
    return test()->postJson('/api/teller/deposits', array_filter([
        'account_number' => 'RPL-2026-001', 'amount' => $amount, 'denominations' => $notes,
    ], fn ($value) => $value !== null));
}

function withdrawNotes(int $amount, ?array $notes = null)
{
    return test()->postJson('/api/teller/withdrawals', array_filter([
        'account_number' => 'RPL-2026-001', 'amount' => $amount, 'pin' => '123456', 'denominations' => $notes,
    ], fn ($value) => $value !== null));
}

/*
|--------------------------------------------------------------------------
| Deposits: exactly the amount, no change
|--------------------------------------------------------------------------
*/

test('a deposit records the notes received and adds them to the drawer', function () {
    depositNotes(35000, [20000 => 1, 5000 => 3])
        ->assertCreated()
        ->assertJsonPath('data.cash', [
            ['denomination' => 20000, 'quantity' => 1],
            ['denomination' => 5000, 'quantity' => 3],
        ]);

    expect(drawer())->toBe([100000 => 1, 50000 => 1, 20000 => 3, 10000 => 1, 5000 => 3]);
});

test('the notes of a deposit must add up exactly to the amount', function () {
    depositNotes(35000, [20000 => 2])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'cash_breakdown_mismatch');

    expect(Transaction::count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(500000);
});

test('only rupiah denominations are accepted', function () {
    depositNotes(30000, [30000 => 1])->assertUnprocessable()->assertJsonValidationErrors('denominations');
    depositNotes(30000, [20000 => -1])->assertUnprocessable()->assertJsonValidationErrors('denominations.20000');
});

test('without notes a deposit is taken as the fewest notes', function () {
    depositNotes(75000)->assertCreated()->assertJsonPath('data.cash', [
        ['denomination' => 50000, 'quantity' => 1],
        ['denomination' => 20000, 'quantity' => 1],
        ['denomination' => 5000, 'quantity' => 1],
    ]);
});

/*
|--------------------------------------------------------------------------
| Withdrawals: only notes the drawer holds
|--------------------------------------------------------------------------
*/

test('a withdrawal pays out notes from the drawer', function () {
    withdrawNotes(60000, [20000 => 2, 10000 => 2])->assertUnprocessable()
        ->assertJsonPath('code', 'drawer_insufficient')
        ->assertJsonPath('shortages.10000', ['requested' => 2, 'available' => 1]);

    withdrawNotes(60000, [50000 => 1, 10000 => 1])->assertCreated();

    expect(drawer())->toBe([100000 => 1, 20000 => 2])
        ->and($this->customer->fresh()->balance)->toBe(440000);
});

test('a note paid out once cannot be paid out again', function () {
    withdrawNotes(50000, [50000 => 1])->assertCreated();

    withdrawNotes(50000, [50000 => 1])
        ->assertUnprocessable()
        ->assertJsonPath('shortages.50000', ['requested' => 1, 'available' => 0]);
});

test('without notes the system pays with notes the drawer can combine', function () {
    // 40.000 cannot start with the 50.000 note; 2 x 20.000 works.
    withdrawNotes(40000)->assertCreated()->assertJsonPath('data.cash', [['denomination' => 20000, 'quantity' => 2]]);

    // 160.000 = 100.000 + 50.000 + 10.000
    withdrawNotes(160000)->assertCreated();
    expect(drawer())->toBe([]);
});

test('a withdrawal the drawer cannot pay is refused and nothing changes', function () {
    withdrawNotes(5000)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'drawer_insufficient');

    // More than the whole drawer.
    withdrawNotes(250000)->assertUnprocessable()->assertJsonPath('code', 'drawer_insufficient');

    expect(Transaction::count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(500000)
        ->and(drawer())->toBe([100000 => 1, 50000 => 1, 20000 => 2, 10000 => 1]);
});

test('notes received earlier in the shift can be paid out', function () {
    depositNotes(5000, [5000 => 1])->assertCreated();

    withdrawNotes(5000, [5000 => 1])->assertCreated();
});

test('a withdrawal approved in the app is paid from the drawer when the teller completes it', function () {
    $id = $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 30000])
        ->assertCreated()->json('data.id');
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/customer/withdrawal-authorizations/{$id}/approve", ['pin' => '123456'])->assertOk();
    Sanctum::actingAs($this->teller);

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete", ['denominations' => [10000 => 3]])
        ->assertUnprocessable()->assertJsonPath('code', 'drawer_insufficient');

    $this->postJson("/api/teller/withdrawal-authorizations/{$id}/complete", ['denominations' => [20000 => 1, 10000 => 1]])
        ->assertCreated()
        ->assertJsonPath('data.cash', [['denomination' => 20000, 'quantity' => 1], ['denomination' => 10000, 'quantity' => 1]]);
});

/*
|--------------------------------------------------------------------------
| Corrections move the same notes back
|--------------------------------------------------------------------------
*/

test('reversing a deposit returns its notes from the drawer', function () {
    $id = depositNotes(25000, [20000 => 1, 5000 => 1])->json('data.id');

    $this->postJson("/api/teller/transactions/{$id}/correct", ['reason' => 'Salah rekening', 'supervisor_username' => 'spv', 'supervisor_password' => 'rahasia123'])
        ->assertCreated()
        ->assertJsonPath('data.cash', [['denomination' => 20000, 'quantity' => 1], ['denomination' => 5000, 'quantity' => 1]]);

    expect(drawer())->toBe([100000 => 1, 50000 => 1, 20000 => 2, 10000 => 1]);
});

/*
|--------------------------------------------------------------------------
| Closing: every denomination must match
|--------------------------------------------------------------------------
*/

test('closing needs the right notes, not only the right total', function () {
    depositNotes(30000, [10000 => 3])->assertCreated();
    withdrawNotes(50000, [50000 => 1])->assertCreated();
    // Drawer: 100.000 x1, 20.000 x2, 10.000 x4 = Rp180.000.

    $this->getJson('/api/teller/shift')
        ->assertOk()
        ->assertJsonPath('data.cash_tracked', true)
        ->assertJsonPath('drawer', [
            ['denomination' => 100000, 'quantity' => 1, 'subtotal' => 100000],
            ['denomination' => 20000, 'quantity' => 2, 'subtotal' => 40000],
            ['denomination' => 10000, 'quantity' => 4, 'subtotal' => 40000],
        ]);

    // Same Rp180.000, but as 50.000 x2 + ... : refused, with the notes that differ.
    $this->postJson('/api/teller/shift/close', ['denominations' => [100000 => 1, 50000 => 1, 10000 => 3]])
        ->assertUnprocessable()
        ->assertJsonPath('difference', 0)
        ->assertJsonPath('denominations.50000', ['expected' => 0, 'counted' => 1, 'difference' => 1])
        ->assertJsonPath('denominations.20000', ['expected' => 2, 'counted' => 0, 'difference' => -2])
        ->assertJsonPath('denominations.10000', ['expected' => 4, 'counted' => 3, 'difference' => -1])
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'pecahannya tidak sesuai'));

    // A wrong total names the notes too.
    $this->postJson('/api/teller/shift/close', ['denominations' => [100000 => 1, 20000 => 2, 10000 => 3]])
        ->assertUnprocessable()
        ->assertJsonPath('difference', -10000)
        ->assertJsonPath('denominations.10000.difference', -1);

    expect($this->shift->fresh()->status->value)->toBe('open');

    $this->postJson('/api/teller/shift/close', ['denominations' => [100000 => 1, 20000 => 2, 10000 => 4]])
        ->assertOk()
        ->assertJsonPath('data.physical_balance', 180000);
});

test('a shift opened before per-note tracking keeps the total-only check', function () {
    // The state of a shift that was open when v0.3.0 was deployed.
    $this->shift->update(['cash_tracked' => false]);

    withdrawNotes(200000)->assertCreated();
    depositNotes(30000)->assertCreated();

    expect(TransactionCash::count())->toBe(0);

    $this->getJson('/api/teller/shift')->assertJsonPath('data.cash_tracked', false)->assertJsonPath('drawer', null);

    // Right total, any notes.
    $this->postJson('/api/teller/shift/close', ['denominations' => [20000 => 1, 5000 => 2]])->assertOk();
});

test('recorded notes are part of the audit trail and cannot be changed', function () {
    depositNotes(20000, [20000 => 1])->assertCreated();
    $note = TransactionCash::firstOrFail();

    expect(fn () => $note->update(['quantity' => 2]))->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
});

test('the service checks the drawer under the shift lock', function () {
    // Two withdrawals for the last 50.000 note: the second sees the first one's payout.
    $service = app(TransactionService::class);
    $service->withdraw($this->customer, 50000, $this->teller, '123456', notes: [50000 => 1]);

    expect(fn () => $service->withdraw($this->customer, 50000, $this->teller, '123456', notes: [50000 => 1]))
        ->toThrow(\App\Exceptions\CashBreakdownException::class);

    app(DailyReportService::class)->close($this->teller, drawer());
    expect($this->shift->fresh()->physical_balance)->toBe(150000);
});
