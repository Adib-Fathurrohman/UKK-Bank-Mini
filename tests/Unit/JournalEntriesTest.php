<?php

use App\Enums\AccountCode;
use App\Enums\Position;
use App\Enums\TransactionTypes;
use App\Services\JournalService;

test('every transaction type maps to one debit and one credit on different accounts', function (TransactionTypes $type) {
    $entries = JournalService::entriesFor($type);

    expect($entries)->toHaveCount(2)
        ->and(array_column($entries, 'position'))->toEqualCanonicalizing([Position::DEBIT, Position::CREDIT])
        ->and(array_unique(array_map(fn ($entry) => $entry['account_code']->value, $entries)))->toHaveCount(2);
})->with(TransactionTypes::cases());

test('deposit increases cash and customer savings', function () {
    expect(JournalService::entriesFor(TransactionTypes::DEPOSIT))->toBe([
        ['account_code' => AccountCode::CASH, 'position' => Position::DEBIT],
        ['account_code' => AccountCode::CUSTOMER_SAVINGS, 'position' => Position::CREDIT],
    ]);
});

test('withdrawal decreases customer savings and cash', function () {
    expect(JournalService::entriesFor(TransactionTypes::WITHDRAWAL))->toBe([
        ['account_code' => AccountCode::CUSTOMER_SAVINGS, 'position' => Position::DEBIT],
        ['account_code' => AccountCode::CASH, 'position' => Position::CREDIT],
    ]);
});
