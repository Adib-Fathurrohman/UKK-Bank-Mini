<?php

use App\Enums\AccountStatus;
use App\Enums\TransactionTypes;
use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\Graduation;
use App\Models\Major;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Graduation (kelulusan): the administrator previews a class, confirms the
| accounts, and every one is paid out in full (minimum balance included) from
| the main vault and closed. One slip per student is printed.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-06-10 10:00:00'));
    $this->firebase = fakeFirebase();
    $this->push = fakePush();
    $this->admin = User::factory()->create(['role' => UserRoles::ADMIN, 'full_name' => 'Bu Admin']);
    $this->rpl = Major::factory()->create(['code' => 'RPL']);
    $this->tkj = Major::factory()->create(['code' => 'TKJ']);

    $this->budi = Customer::factory()->activated('uid-budi')->create(['class' => 'XII', 'major_id' => $this->rpl->id, 'group' => '1', 'full_name' => 'Budi', 'account_number' => 'RPL-2024-001', 'balance' => 150000]);
    $this->sari = Customer::factory()->create(['class' => 'XII', 'major_id' => $this->rpl->id, 'group' => '2', 'full_name' => 'Sari', 'account_number' => 'RPL-2024-002', 'balance' => 10000]);
    $this->kosong = Customer::factory()->create(['class' => 'XII', 'major_id' => $this->tkj->id, 'group' => '1', 'full_name' => 'Kosong', 'account_number' => 'TKJ-2024-001', 'balance' => 0]);
    // Not graduating.
    $this->adik = Customer::factory()->create(['class' => 'XI', 'major_id' => $this->rpl->id, 'group' => '1', 'balance' => 80000]);

    Sanctum::actingAs($this->admin);
});

function previewIds(array $query = ['class' => 'XII']): array
{
    return collect(test()->getJson('/api/admin/graduations/preview?'.http_build_query($query))->assertOk()->json('data'))->pluck('id')->all();
}

function graduate(array $body = [])
{
    $filters = array_intersect_key($body + ['class' => 'XII'], array_flip(['class', 'major_id', 'group']));

    return test()->postJson('/api/admin/graduations', $body + ['class' => 'XII', 'customer_ids' => previewIds($filters)]);
}

test('the preview lists the class with each full payout and changes nothing', function () {
    $this->getJson('/api/admin/graduations/preview?class=XII')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.full_name', 'Budi')
        ->assertJsonPath('data.0.payout', 150000)
        ->assertJsonPath('summary', ['customer_count' => 3, 'paid_count' => 2, 'total_amount' => 160000]);

    // A major and group narrow it down.
    $this->getJson("/api/admin/graduations/preview?class=XII&major_id={$this->rpl->id}&group=2")
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Sari');

    $this->getJson('/api/admin/graduations/preview')->assertUnprocessable()->assertJsonValidationErrors('class');

    expect(Transaction::count())->toBe(0)->and(Customer::count())->toBe(4);
});

test('graduating pays out every balance in full and closes every account', function () {
    $response = graduate(['note' => 'Kelulusan 2027'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'XII')
        ->assertJsonPath('data.customer_count', 3)
        ->assertJsonPath('data.paid_count', 2)
        ->assertJsonPath('data.total_amount', 160000)
        ->assertJsonPath('data.executed_by.full_name', 'Bu Admin')
        ->assertJsonCount(3, 'data.customers');

    // Sari's Rp10.000 is the minimum balance: a normal withdrawal could not take it, graduation does.
    $sari = Customer::withTrashed()->find($this->sari->id);
    expect($sari)
        ->balance->toBe(0)
        ->account_status->toBe(AccountStatus::INACTIVE)
        ->trashed()->toBeTrue()
        ->graduation_id->toBe(Graduation::first()->id);

    $payout = Transaction::where('customer_id', $this->budi->id)->firstOrFail();
    expect($payout)
        ->type->toBe(TransactionTypes::WITHDRAWAL)
        ->amount->toBe(150000)
        ->balance_after->toBe(0)
        ->daily_report_id->toBeNull()
        ->user_id->toBe($this->admin->id);

    // Journals like any withdrawal: savings debited, cash credited.
    expect(AccountingJournal::where('transaction_id', $payout->id)->count())->toBe(2);

    // The empty account is closed without a transaction; the younger class is untouched.
    expect(Transaction::where('customer_id', $this->kosong->id)->exists())->toBeFalse()
        ->and(Customer::withTrashed()->find($this->kosong->id)->trashed())->toBeTrue()
        ->and($this->adik->fresh())->balance->toBe(80000)->trashed()->toBeFalse();

    // App logins stop (every factory account here has a Firebase user; the XI student keeps his).
    expect($this->firebase->disabledUids)->toHaveCount(3)->toContain('uid-budi')
        ->not->toContain(Customer::find($this->adik->id)->firebase_uid);
    expect($response->json('data.customers.*.amount'))->toEqualCanonicalizing([150000, 10000, 0]);
});

test('nobody is paid twice', function () {
    $ids = previewIds();
    $this->postJson('/api/admin/graduations', ['class' => 'XII', 'customer_ids' => $ids])->assertCreated();

    // Same request again: the accounts are closed, so they are no longer in the class.
    $this->postJson('/api/admin/graduations', ['class' => 'XII', 'customer_ids' => $ids])
        ->assertUnprocessable()->assertJsonPath('code', 'graduation_empty');

    expect(Transaction::sum('amount'))->toBe(160000)->and(Graduation::count())->toBe(1);
});

test('if the class changed since the preview, nothing happens', function () {
    $ids = previewIds();
    $late = Customer::factory()->create(['class' => 'XII', 'balance' => 25000]);

    $this->postJson('/api/admin/graduations', ['class' => 'XII', 'customer_ids' => $ids])
        ->assertConflict()
        ->assertJsonPath('code', 'graduation_selection_changed')
        ->assertJsonPath('missing_customer_ids', [$late->id]);

    // An account confirmed but no longer in the class (e.g. closed meanwhile).
    $this->postJson('/api/admin/graduations', ['class' => 'XII', 'customer_ids' => [...$ids, $late->id, $this->adik->id]])
        ->assertConflict()
        ->assertJsonPath('unexpected_customer_ids', [$this->adik->id]);

    expect(Transaction::count())->toBe(0)
        ->and(Graduation::count())->toBe(0)
        ->and(Customer::count())->toBe(5);
});

test('graduating cancels what can no longer happen on a closed account', function () {
    $teller = User::factory()->teller()->create();
    $shift = openShiftFor($teller, cashFloat());
    $authorization = WithdrawalAuthorization::create([
        'teller_id' => $teller->id, 'customer_id' => $this->budi->id, 'daily_report_id' => $shift->id,
        'amount' => 20000, 'expires_at' => now()->addMinutes(2),
    ]);
    $this->budi->createToken('app');

    graduate()->assertCreated();

    expect($authorization->fresh())->status->value->toBe('cancelled')->cancel_reason->toBe('account_closed')
        ->and($this->budi->tokens()->count())->toBe(0);
});

test('a graduation can be narrowed to one major and group', function () {
    graduate(['major_id' => $this->rpl->id, 'group' => '1'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'XII RPL 1')
        ->assertJsonPath('data.customer_count', 1);

    expect($this->sari->fresh()->trashed())->toBeFalse();
});

test('slips print one page per paid student, together or one at a time', function () {
    $id = graduate()->json('data.id');

    $all = $this->get("/api/graduations/{$id}/slips")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('kelulusan-xii-20270610.pdf');
    expect(substr($all->getContent(), 0, 5))->toBe('%PDF-')
        // Two students received money: two pages (the empty account gets none).
        ->and(preg_match_all('/\/Type\s*\/Page[^s]/', $all->getContent()))->toBe(2);

    $this->get("/api/graduations/{$id}/slips/{$this->budi->id}")
        ->assertOk()
        ->assertDownload('kelulusan-xii-20270610-RPL-2024-001.pdf');

    $this->get("/api/graduations/{$id}/slips/{$this->kosong->id}")->assertNotFound();
    $this->get("/api/graduations/{$id}/slips/{$this->adik->id}")->assertNotFound();
});

test('the slip shows the student, the amount in words and the closing', function () {
    graduate()->assertCreated();
    $graduation = Graduation::firstOrFail()->load(['major', 'executor']);

    $html = view('pdf.graduation-slips', [
        'graduation' => $graduation,
        'payouts' => $graduation->transactions()->with('customer.major')->get(),
    ])->render();

    expect($html)->toContain('BUKTI PENARIKAN TABUNGAN KELULUSAN')
        ->toContain('Budi')->toContain('RPL-2024-001')
        ->toContain('Rp150.000')->toContain('seratus lima puluh ribu rupiah')
        ->toContain('rekening ditutup')
        ->toContain('Bu Admin')
        ->and(substr_count($html, 'class="slip"'))->toBe(2);
});

test('the statement and audit trail show the graduation payout', function () {
    $id = graduate()->json('data.id');

    $this->getJson("/api/customers/{$this->budi->id}")->assertOk();
    $this->getJson("/api/audit/transactions?customer_id={$this->budi->id}")
        ->assertOk()
        ->assertJsonPath('data.0.graduation_id', $id)
        ->assertJsonPath('data.0.daily_report_id', null);

    $this->getJson('/api/graduations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson("/api/graduations/{$id}")->assertOk()->assertJsonCount(3, 'data.customers');
});

test('a graduation payout cannot be corrected at a counter', function () {
    graduate()->assertCreated();
    $payout = Transaction::firstOrFail();
    $teller = User::factory()->teller()->create();
    openShiftFor($teller, cashFloat());
    User::factory()->supervisor()->create(['username' => 'spv', 'password' => 'rahasia123']);
    Sanctum::actingAs($teller);

    $this->postJson("/api/teller/transactions/{$payout->id}/correct", ['reason' => 'x', 'supervisor_username' => 'spv', 'supervisor_password' => 'rahasia123'])
        ->assertNotFound();
});

test('only administrators graduate; supervisors can read and print', function () {
    $id = graduate()->json('data.id');

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->getJson('/api/admin/graduations/preview?class=XI')->assertForbidden();
    $this->postJson('/api/admin/graduations', ['class' => 'XI', 'customer_ids' => [$this->adik->id]])->assertForbidden();
    $this->getJson("/api/graduations/{$id}")->assertOk();
    $this->get("/api/graduations/{$id}/slips")->assertOk();

    actingAsStaff(UserRoles::TELLER);
    $this->getJson('/api/graduations')->assertForbidden();
});

test('the database only accepts graduation payouts as withdrawals of a batch', function () {
    graduate()->assertCreated();
    $graduation = Graduation::firstOrFail();

    expect(fn () => Transaction::create([
        'user_id' => $this->admin->id, 'customer_id' => $this->adik->id, 'graduation_id' => $graduation->id,
        'type' => TransactionTypes::DEPOSIT, 'amount' => 1000, 'balance_before' => 0, 'balance_after' => 1000,
    ]))->toThrow(QueryException::class);

    expect(fn () => $graduation->update(['note' => 'ubah']))->toThrow(LogicException::class);
});
