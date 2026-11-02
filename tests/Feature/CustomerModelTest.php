<?php

use App\Enums\AccountStatus;
use App\Models\Customer;
use App\Models\Major;

test('customer is related to a major and hides its pin', function () {
    $major = Major::create([
        'code' => 'RPL',
        'major_name' => 'Rekayasa Perangkat Lunak',
    ]);

    $customer = Customer::create([
        'account_number' => '1000000001',
        'nis' => '20260001',
        'full_name' => 'Budi Santoso',
        'class' => 'X',
        'major_id' => $major->id,
        'group' => '1',
        'account_status' => AccountStatus::ACTIVE,
        'pin' => 'hashed-pin',
        // A PIN only exists on an activated account.
        'firebase_uid' => 'uid-'.uniqid(),
        'activated_at' => now(),
        'balance' => 0,
    ]);

    expect($customer->major->is($major))->toBeTrue()
        ->and($major->customers)->toHaveCount(1)
        ->and($customer->toArray())->not->toHaveKey('pin');
});

test('customer can be soft deleted and restored', function () {
    $major = Major::create([
        'code' => 'AK',
        'major_name' => 'Akuntansi dan Keuangan Lembaga',
    ]);

    $customer = Customer::create([
        'account_number' => '1000000002',
        'nis' => '20260002',
        'full_name' => 'Andi Pratama',
        'class' => 'XI',
        'major_id' => $major->id,
        'group' => '2',
        'account_status' => AccountStatus::INACTIVE,
        'pin' => 'hashed-pin',
        // A PIN only exists on an activated account.
        'firebase_uid' => 'uid-'.uniqid(),
        'activated_at' => now(),
        'balance' => 0,
    ]);

    $customer->delete();

    expect(Customer::find($customer->id))->toBeNull()
        ->and(Customer::withTrashed()->find($customer->id))->not->toBeNull();

    $customer->restore();

    expect(Customer::find($customer->id))->not->toBeNull();
});