<?php

use App\Enums\UserRoles;
use App\Models\ClassPromotion;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Kenaikan kelas: after class XII was graduated, X moves to XI and XI to XII,
| except students who stay. Once per school year, all or nothing.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2027-06-20 10:00:00'));
    $this->admin = User::factory()->create(['role' => UserRoles::ADMIN]);
    $this->x1 = Customer::factory()->create(['class' => 'X', 'full_name' => 'Ani', 'balance' => 20000]);
    $this->x2 = Customer::factory()->create(['class' => 'X', 'full_name' => 'Bayu']);
    $this->xi = Customer::factory()->create(['class' => 'XI', 'full_name' => 'Citra', 'account_number' => 'RPL-2025-001']);
    $this->closedXii = Customer::factory()->create(['class' => 'XII']);
    $this->closedXii->delete(); // graduated earlier
    Sanctum::actingAs($this->admin);
});

function promotionIds(): array
{
    return collect(test()->getJson('/api/admin/class-promotions/preview')->json('data'))->pluck('id')->all();
}

test('the preview lists X and XI with their next class and whether it can run', function () {
    $this->getJson('/api/admin/class-promotions/preview')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.full_name', 'Citra')
        ->assertJsonPath('data.0.next_class', 'XII')
        ->assertJsonPath('data.1.next_class', 'XI')
        ->assertJsonPath('summary', ['school_year' => '2027/2028', 'x_count' => 2, 'xi_count' => 1, 'open_xii_count' => 0, 'already_done' => false, 'can_run' => true]);
});

test('every account moves up one class; account and balance stay', function () {
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds(), 'note' => 'Kenaikan 2027'])
        ->assertCreated()
        ->assertJsonPath('data.school_year', '2027/2028')
        ->assertJsonPath('data.promoted_count', 3)
        ->assertJsonPath('data.retained_count', 0)
        ->assertJsonCount(3, 'data.items');

    expect($this->x1->fresh())->class->toBe('XI')->balance->toBe(20000)
        ->and($this->x2->fresh()->class)->toBe('XI')
        ->and($this->xi->fresh())->class->toBe('XII')->account_number->toBe('RPL-2025-001')
        // Nobody skips a class.
        ->and(Customer::where('class', 'X')->count())->toBe(0);
});

test('students who stay keep their class', function () {
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds(), 'retained_customer_ids' => [$this->x2->id]])
        ->assertCreated()
        ->assertJsonPath('data.promoted_count', 2)
        ->assertJsonPath('data.retained_count', 1);

    expect($this->x2->fresh()->class)->toBe('X')->and($this->x1->fresh()->class)->toBe('XI');

    $item = ClassPromotion::first()->items()->where('customer_id', $this->x2->id)->first();
    expect($item->isRetained())->toBeTrue();
});

test('class XII must be graduated first', function () {
    $open = Customer::factory()->create(['class' => 'XII']);

    $this->getJson('/api/admin/class-promotions/preview')->assertJsonPath('summary.open_xii_count', 1)->assertJsonPath('summary.can_run', false);
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds()])
        ->assertConflict()
        ->assertJsonPath('code', 'promotion_xii_not_empty')
        ->assertJsonPath('open_xii_count', 1);

    expect($this->xi->fresh()->class)->toBe('XI')->and(ClassPromotion::count())->toBe(0);
});

test('it runs once per school year', function () {
    $ids = promotionIds();
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => $ids])->assertCreated();

    // The new XII must graduate before a second run anyway; even then, not twice in 2027/2028.
    Customer::where('class', 'XII')->each(fn ($c) => $c->delete());
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds()])
        ->assertConflict()->assertJsonPath('code', 'promotion_already_done');

    expect($this->x1->fresh()->class)->toBe('XI');

    // The next school year it runs again.
    $this->travelTo(Carbon::parse('2028-06-20 10:00:00'));
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds()])->assertCreated()->assertJsonPath('data.school_year', '2028/2029');
    expect($this->x1->fresh()->class)->toBe('XII');
});

test('if the accounts changed since the preview, nothing happens', function () {
    $ids = promotionIds();
    $new = Customer::factory()->create(['class' => 'X']);

    $this->postJson('/api/admin/class-promotions', ['customer_ids' => $ids])
        ->assertConflict()->assertJsonPath('code', 'promotion_selection_changed')->assertJsonPath('missing_customer_ids', [$new->id]);
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => [...$ids, $new->id], 'retained_customer_ids' => [$this->closedXii->id]])
        ->assertConflict()->assertJsonPath('unexpected_customer_ids', [$this->closedXii->id]);

    expect($this->x1->fresh()->class)->toBe('X');
});

test('history is readable by supervisors; only administrators run it', function () {
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds()])->assertCreated();
    $id = ClassPromotion::first()->public_id;

    actingAsStaff(UserRoles::SUPERVISOR);
    $this->getJson('/api/class-promotions')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/class-promotions/{$id}")->assertOk()->assertJsonCount(3, 'data.items');
    $this->getJson('/api/admin/class-promotions/preview')->assertForbidden();

    actingAsStaff(UserRoles::TELLER);
    $this->getJson('/api/class-promotions')->assertForbidden();
});

test('the database only accepts one-step moves', function () {
    $this->postJson('/api/admin/class-promotions', ['customer_ids' => promotionIds()])->assertCreated();

    expect(fn () => ClassPromotion::first()->items()->create(['customer_id' => $this->x1->id, 'from_class' => 'X', 'to_class' => 'XII']))
        ->toThrow(QueryException::class);
});
