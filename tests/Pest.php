<?php

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Setting;
use App\Models\User;
use App\Services\DailyReportService;
use App\Support\Cash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Contracts\FirebaseAuth;
use App\Contracts\PushNotifier;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeFirebaseAuth;
use Tests\Support\FakePushNotifier;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Authenticate the next requests as a staff member with the given role.
 */
function actingAsStaff(UserRoles $role = UserRoles::TELLER, array $attributes = []): User
{
    $user = User::factory()->create(['role' => $role, ...$attributes]);

    Sanctum::actingAs($user);

    return $user;
}

/**
 * Open today's shift for a teller with the given opening cash.
 */
function openShiftFor(User $teller, int|array $openingBalance = 0): DailyReport
{
    $counts = is_array($openingBalance) ? $openingBalance : Cash::breakdown($openingBalance);

    return app(DailyReportService::class)->open($teller, $counts);
}

/**
 * A realistic change float for the drawer (Rp980.000 in mixed notes), so
 * withdrawals of any usual amount can be paid out: openShiftFor($teller, cashFloat()).
 *
 * @return array<int, int>
 */
function cashFloat(): array
{
    return [100000 => 5, 50000 => 4, 20000 => 5, 10000 => 10, 5000 => 10, 2000 => 10, 1000 => 10];
}

/**
 * Close the teller's active shift with exactly the cash the system expects.
 */
function closeShiftFor(User $teller): DailyReport
{
    $reports = app(DailyReportService::class);
    $shift = $reports->activeShift($teller);

    // Tracked shifts must match the drawer per denomination; older ones only in total.
    $counts = $shift->cash_tracked
        ? app(\App\Services\CashDrawerService::class)->contents($shift)
        : Cash::breakdown($reports->summarize($shift)['system_balance']);

    return $reports->close($teller, $counts);
}

/**
 * Allow withdrawals with the PIN typed at the teller terminal
 * (POST /teller/withdrawals); the default mode only allows mobile approval.
 */
function allowTerminalWithdrawals(string $mode = 'terminal'): void
{
    Setting::updateOrCreate(['key' => 'withdrawal_verification'], ['value' => $mode]);
}

/**
 * Authenticate the next requests as a customer (student).
 */
function actingAsCustomer(array $attributes = []): Customer
{
    $customer = Customer::factory()->create($attributes);

    Sanctum::actingAs($customer);

    return $customer;
}

/**
 * Replace Firebase Authentication with the in-memory fake for this test.
 */
function fakeFirebase(): FakeFirebaseAuth
{
    $fake = new FakeFirebaseAuth;

    app()->instance(FirebaseAuth::class, $fake);

    return $fake;
}

/**
 * Replace FCM push notifications with the in-memory fake for this test.
 */
function fakePush(): FakePushNotifier
{
    $fake = new FakePushNotifier;

    app()->instance(PushNotifier::class, $fake);

    return $fake;
}
