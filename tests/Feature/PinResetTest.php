<?php

use App\Enums\PinResetStatus;
use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\PinResetRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/*
| Forgotten PIN, self-service: the customer asks with a fresh Firebase
| sign-in, an administrator approves, and the new PIN can only be set after the
| waiting period (24 hours). While a request is open, withdrawals are paused.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-08 09:00:00'));
    $this->firebase = fakeFirebase();
    $this->push = fakePush();
    $this->customer = Customer::factory()->activated('uid-budi')->create([
        'nis' => '20260001',
        'account_number' => 'RPL-2026-001',
        'email' => 'budi@example.test',
        'pin' => '123456',
        'balance' => 100000,
    ]);
    $this->admin = User::factory()->admin()->create();
});

function budiToken(): string
{
    return test()->firebase->issue('uid-budi', 'budi@example.test');
}

/** As the customer: ask for a reset. */
function askForPinReset(?Customer $customer = null, ?string $token = null)
{
    Sanctum::actingAs($customer ?? test()->customer);

    return test()->postJson('/api/customer/pin-reset-requests', ['id_token' => $token ?? budiToken()]);
}

/** As the admin: approve (or reject) the request. */
function decidePinReset(string $id, string $decision = 'approve', ?string $note = null, ?User $admin = null)
{
    Sanctum::actingAs($admin ?? test()->admin);

    return test()->postJson("/api/admin/pin-reset-requests/{$id}/{$decision}", $note ? ['note' => $note] : []);
}

/** As the customer: set the new PIN of the request. */
function completePinReset(string $id, string $pin = '654321', ?Customer $customer = null, ?string $token = null)
{
    Sanctum::actingAs($customer ?? test()->customer);

    return test()->postJson("/api/customer/pin-reset-requests/{$id}/complete", [
        'id_token' => $token ?? budiToken(),
        'pin' => $pin,
        'pin_confirmation' => $pin,
    ]);
}

function approvedPinReset(): string
{
    $id = askForPinReset()->assertCreated()->json('data.id');
    decidePinReset($id)->assertOk();

    return $id;
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

test('ask, get approved, wait 24 hours, then set the new PIN', function () {
    $response = askForPinReset()
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.ready', false);
    $id = $response->json('data.id');
    expect(Carbon::parse($response->json('data.available_at'))->equalTo(Carbon::parse('2026-11-09 09:00:00')))->toBeTrue();

    // The customer's phones are told.
    expect($this->push->sent)->toHaveCount(1)
        ->and($this->push->sent[0]['data'])->toMatchArray(['type' => 'pin_reset', 'id' => $id]);

    decidePinReset($id, 'approve', 'KTM dicek langsung')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decision_note', 'KTM dicek langsung')
        ->assertJsonPath('data.decided_by', $this->admin->full_name)
        ->assertJsonPath('data.customer.account_number', 'RPL-2026-001');

    // Approved, but the waiting period is not over.
    completePinReset($id)
        ->assertConflict()
        ->assertJsonPath('code', 'pin_reset_not_ready')
        ->assertJsonStructure(['available_at']);
    expect(Hash::check('123456', $this->customer->fresh()->pin))->toBeTrue();

    $this->travelTo(Carbon::parse('2026-11-09 09:00:00'));

    completePinReset($id)->assertOk()->assertJsonPath('message', 'PIN berhasil diganti.');

    $customer = $this->customer->fresh();
    expect(Hash::check('654321', $customer->pin))->toBeTrue()
        ->and(Hash::check('123456', $customer->pin))->toBeFalse()
        ->and(PinResetRequest::where('public_id', $id)->first())
        ->status->toBe(PinResetStatus::COMPLETED)
        ->open_customer_id->toBeNull()
        ->completed_at->not->toBeNull();

    // Told again, and the new PIN works in the app.
    expect(collect($this->push->sent)->pluck('title')->all())->toBe(['Permintaan reset PIN', 'Reset PIN disetujui', 'PIN diubah']);
    Sanctum::actingAs($customer);
    $this->postJson('/api/customer/unlock', ['pin' => '654321'])->assertOk();
    $this->postJson('/api/customer/unlock', ['pin' => '123456'])->assertUnprocessable();
});

test('completing the reset also lifts a PIN lock', function () {
    $id = approvedPinReset();
    $this->customer->update(['pin_failed_attempts' => 2, 'pin_locked_until' => now()->addMinutes(10)]);
    $this->travelTo(now()->addDay());

    completePinReset($id)->assertOk();

    expect($this->customer->fresh())->pin_failed_attempts->toBe(0)->pin_locked_until->toBeNull();
});

test('the customer sees the open request and null when there is none', function () {
    Sanctum::actingAs($this->customer);
    $this->getJson('/api/customer/pin-reset-request')->assertOk()->assertJsonPath('data', null);

    $id = askForPinReset()->json('data.id');

    $this->getJson('/api/customer/pin-reset-request')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.customer');
});

/*
|--------------------------------------------------------------------------
| Asking
|--------------------------------------------------------------------------
*/

test('asking needs a fresh sign-in of the Firebase account bound to this customer', function () {
    askForPinReset(token: $this->firebase->issue('uid-someone-else', 'budi@example.test'))
        ->assertForbidden()
        ->assertJsonPath('code', 'identity_mismatch');

    askForPinReset(token: 'garbage')->assertUnauthorized()->assertJsonPath('code', 'firebase_token_invalid');

    Sanctum::actingAs($this->customer);
    $this->postJson('/api/customer/pin-reset-requests', [])->assertUnprocessable();

    expect(PinResetRequest::count())->toBe(0);
});

test('a customer without a PIN has nothing to reset', function () {
    $this->customer->update(['pin' => null]);

    askForPinReset()->assertUnprocessable()->assertJsonPath('code', 'pin_not_set');
});

test('only one request can be open at a time', function () {
    askForPinReset()->assertCreated();

    askForPinReset()->assertConflict()->assertJsonPath('code', 'pin_reset_already_requested');

    expect(PinResetRequest::count())->toBe(1);
});

test('the database allows one open request per customer', function () {
    PinResetRequest::create([
        'customer_id' => $this->customer->id,
        'open_customer_id' => $this->customer->id,
        'available_at' => now()->addDay(),
        'expires_at' => now()->addWeek(),
    ]);

    expect(fn () => PinResetRequest::create([
        'customer_id' => $this->customer->id,
        'open_customer_id' => $this->customer->id,
        'available_at' => now()->addDay(),
        'expires_at' => now()->addWeek(),
    ]))->toThrow(QueryException::class);

    // An open request must be marked open, a finished one must not.
    expect(fn () => PinResetRequest::create([
        'customer_id' => $this->customer->id,
        'status' => PinResetStatus::REJECTED,
        'open_customer_id' => $this->customer->id,
        'available_at' => now(),
        'expires_at' => now()->addWeek(),
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Approval and the waiting period
|--------------------------------------------------------------------------
*/

test('the new PIN cannot be set before an administrator approves, however long the wait', function () {
    $id = askForPinReset()->json('data.id');
    $this->travelTo(now()->addDays(2));

    completePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_approved');

    expect(Hash::check('123456', $this->customer->fresh()->pin))->toBeTrue();
});

test('approving late does not shorten the wait, approving early does not skip it', function () {
    $id = askForPinReset()->json('data.id');

    // Approved right away: still 24 hours after the request.
    decidePinReset($id)->assertOk();
    $this->travelTo(now()->addHours(23));
    completePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_ready');
    $this->travelTo(now()->addHour());
    completePinReset($id)->assertOk();
});

test('an administrator can reject a request, and the customer may then ask again', function () {
    $id = askForPinReset()->json('data.id');

    decidePinReset($id, 'reject', 'Bukan nasabah yang bersangkutan')
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
    expect(collect($this->push->sent)->pluck('title')->last())->toBe('Reset PIN ditolak');

    completePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable');
    decidePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable');

    askForPinReset()->assertCreated();
});

test('a decision can only be made once', function () {
    $id = askForPinReset()->json('data.id');
    decidePinReset($id)->assertOk();

    decidePinReset($id, 'reject')->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable');
    decidePinReset($id)->assertConflict();
});

test('the note is optional and limited to 255 characters', function () {
    $id = askForPinReset()->json('data.id');

    decidePinReset($id, 'approve', str_repeat('x', 256))->assertUnprocessable();

    decidePinReset($id)->assertOk()->assertJsonPath('data.decision_note', null);
});

test('a PIN reset request can be completed only once', function () {
    $id = approvedPinReset();
    $this->travelTo(now()->addDay());

    completePinReset($id)->assertOk();
    completePinReset($id, '999999')->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable');

    expect(Hash::check('654321', $this->customer->fresh()->pin))->toBeTrue();
});

test('setting the new PIN needs the same fresh sign-in and a confirmed 6-digit PIN', function () {
    $id = approvedPinReset();
    $this->travelTo(now()->addDay());

    completePinReset($id, token: $this->firebase->issue('uid-someone-else', 'budi@example.test'))
        ->assertForbidden()->assertJsonPath('code', 'identity_mismatch');

    Sanctum::actingAs($this->customer);
    $this->postJson("/api/customer/pin-reset-requests/{$id}/complete", ['id_token' => budiToken(), 'pin' => '12345', 'pin_confirmation' => '12345'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    $this->postJson("/api/customer/pin-reset-requests/{$id}/complete", ['id_token' => budiToken(), 'pin' => '654321', 'pin_confirmation' => '111111'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');

    expect(Hash::check('123456', $this->customer->fresh()->pin))->toBeTrue();
    completePinReset($id)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Cancelling and expiry
|--------------------------------------------------------------------------
*/

test('the customer can cancel a pending or approved request', function () {
    $id = askForPinReset()->json('data.id');
    $this->postJson("/api/customer/pin-reset-requests/{$id}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $id = askForPinReset()->assertCreated()->json('data.id');
    decidePinReset($id)->assertOk();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/customer/pin-reset-requests/{$id}/cancel")->assertOk();

    $this->travelTo(now()->addDay());
    completePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable');
    expect(Hash::check('123456', $this->customer->fresh()->pin))->toBeTrue()
        ->and(PinResetRequest::where('public_id', $id)->first()->cancel_reason)->toBe('customer');
});

test('a request expires after 7 days and the customer may ask again', function () {
    $id = approvedPinReset();

    $this->travelTo(now()->addDays(7));

    completePinReset($id)->assertConflict()->assertJsonPath('code', 'pin_reset_not_usable')->assertJsonPath('status', 'expired');
    askForPinReset()->assertCreated();
});

test('the scheduled command closes abandoned requests', function () {
    $id = askForPinReset()->json('data.id');
    $this->travelTo(now()->addDays(8));

    $this->artisan('bank:expire-pin-reset-requests')->expectsOutput('Expired: 1')->assertSuccessful();

    expect(PinResetRequest::where('public_id', $id)->first())
        ->status->toBe(PinResetStatus::EXPIRED)
        ->open_customer_id->toBeNull();
});

test('a finished request is an audit record that cannot be changed or deleted', function () {
    $id = askForPinReset()->json('data.id');
    decidePinResetRejected($id);
    $request = PinResetRequest::where('public_id', $id)->firstOrFail();

    expect(fn () => $request->update(['decision_note' => 'edit']))->toThrow(LogicException::class)
        ->and(fn () => $request->delete())->toThrow(LogicException::class);
});

function decidePinResetRejected(string $id): void
{
    decidePinReset($id, 'reject')->assertOk();
}

test("other customers' requests do not exist", function () {
    $id = askForPinReset()->json('data.id');
    $other = Customer::factory()->activated('uid-siti')->create();
    $this->firebase->issue('uid-siti', 'siti@example.test');

    Sanctum::actingAs($other);
    $this->postJson("/api/customer/pin-reset-requests/{$id}/cancel")->assertNotFound();
    $this->postJson("/api/customer/pin-reset-requests/{$id}/complete", [
        'id_token' => $this->firebase->issue('uid-siti', 'siti@example.test'), 'pin' => '654321', 'pin_confirmation' => '654321',
    ])->assertNotFound();
    $this->getJson('/api/customer/pin-reset-request')->assertJsonPath('data', null);
});

/*
|--------------------------------------------------------------------------
| Withdrawals pause while a request is open
|--------------------------------------------------------------------------
*/

describe('withdrawals while a reset request is open', function () {
    beforeEach(function () {
        $this->teller = User::factory()->teller()->create();
        openShiftFor($this->teller, cashFloat()); // change float, so withdrawals can be paid out
        allowTerminalWithdrawals('both');
    });

    test('are refused at the counter and in teller authorizations', function () {
        $id = askForPinReset()->json('data.id');

        Sanctum::actingAs($this->teller);
        $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'])
            ->assertUnprocessable()->assertJsonPath('code', 'pin_reset_in_progress');
        $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 20000])
            ->assertUnprocessable()->assertJsonPath('code', 'pin_reset_in_progress');
        expect($this->customer->fresh()->balance)->toBe(100000);

        // Deposits are not affected.
        $this->postJson('/api/teller/deposits', ['account_number' => 'RPL-2026-001', 'amount' => 5000])->assertCreated();

        // Cancelling the request lifts the pause.
        Sanctum::actingAs($this->customer);
        $this->postJson("/api/customer/pin-reset-requests/{$id}/cancel")->assertOk();
        Sanctum::actingAs($this->teller);
        $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'])->assertCreated();
    });

    test('are refused for an approval already waiting in the app', function () {
        Sanctum::actingAs($this->teller);
        $authorization = $this->postJson('/api/teller/withdrawal-authorizations', ['account_number' => 'RPL-2026-001', 'amount' => 20000])
            ->assertCreated()->json('data.id');

        askForPinReset()->assertCreated();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/customer/withdrawal-authorizations/{$authorization}/approve", ['pin' => '123456'])
            ->assertUnprocessable()->assertJsonPath('code', 'pin_reset_in_progress');
    });

    test('resume once the reset is completed', function () {
        $id = approvedPinReset();
        $this->travelTo(now()->addDay());
        completePinReset($id)->assertOk();

        // A new day, a new shift.
        $teller = User::factory()->teller()->create();
        openShiftFor($teller, cashFloat()); // change float, so withdrawals can be paid out
        Sanctum::actingAs($teller);
        $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '123456'])->assertUnprocessable();
        $this->postJson('/api/teller/withdrawals', ['account_number' => 'RPL-2026-001', 'amount' => 20000, 'pin' => '654321'])->assertCreated();
    });
});

test('an administrator PIN reset closes the open request', function () {
    $id = askForPinReset()->json('data.id');

    Sanctum::actingAs($this->admin);
    $this->postJson("/api/admin/customers/{$this->customer->id}/pin-reset")->assertOk();

    expect(PinResetRequest::where('public_id', $id)->first())
        ->status->toBe(PinResetStatus::CANCELLED)
        ->cancel_reason->toBe('admin_reset')
        ->open_customer_id->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Staff side
|--------------------------------------------------------------------------
*/

test('administrators and supervisors list requests, only administrators decide', function () {
    $id = askForPinReset()->json('data.id');
    $other = Customer::factory()->activated()->create();
    PinResetRequest::create([
        'customer_id' => $other->id,
        'status' => PinResetStatus::REJECTED,
        'available_at' => now(),
        'expires_at' => now()->addWeek(),
        'decided_by' => $this->admin->id,
        'rejected_at' => now(),
    ]);

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->getJson('/api/pin-reset-requests')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/pin-reset-requests?status=pending')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.customer.full_name', $this->customer->full_name);
    $this->getJson("/api/pin-reset-requests?customer_id={$other->id}")->assertJsonCount(1, 'data');
    $this->getJson("/api/pin-reset-requests/{$id}")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->getJson('/api/pin-reset-requests?status=bogus')->assertUnprocessable();
    $this->postJson("/api/admin/pin-reset-requests/{$id}/approve")->assertForbidden();

    actingAsStaff(UserRoles::TELLER);
    $this->getJson('/api/pin-reset-requests')->assertForbidden();
    $this->postJson("/api/admin/pin-reset-requests/{$id}/reject")->assertForbidden();

    expect(PinResetRequest::where('public_id', $id)->first()->status)->toBe(PinResetStatus::PENDING);
});

test('the list expires overdue requests first', function () {
    askForPinReset()->assertCreated();
    $this->travelTo(now()->addDays(8));

    Sanctum::actingAs($this->admin);
    $this->getJson('/api/pin-reset-requests?status=pending')->assertJsonCount(0, 'data');
    $this->getJson('/api/pin-reset-requests?status=expired')->assertJsonCount(1, 'data');
});

test('push failures never break the request', function () {
    $this->push->failing = true;

    askForPinReset()->assertCreated();
});
