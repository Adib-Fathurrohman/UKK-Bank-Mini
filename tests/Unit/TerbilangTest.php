<?php

use App\Support\Rupiah;
use App\Support\Terbilang;

test('amounts are written in Indonesian words', function (int $amount, string $words) {
    expect(Terbilang::words($amount))->toBe($words);
})->with([
    [0, 'nol'],
    [1, 'satu'],
    [11, 'sebelas'],
    [12, 'dua belas'],
    [19, 'sembilan belas'],
    [20, 'dua puluh'],
    [99, 'sembilan puluh sembilan'],
    [100, 'seratus'],
    [111, 'seratus sebelas'],
    [250, 'dua ratus lima puluh'],
    [1000, 'seribu'],
    [1500, 'seribu lima ratus'],
    [10000, 'sepuluh ribu'],
    [11000, 'sebelas ribu'],
    [100000, 'seratus ribu'],
    [125500, 'seratus dua puluh lima ribu lima ratus'],
    [1000000, 'satu juta'],
    [1500000, 'satu juta lima ratus ribu'],
    [2075000, 'dua juta tujuh puluh lima ribu'],
    [1000000000, 'satu miliar'],
]);

test('terbilang rupiah adds the currency', function () {
    expect(Terbilang::rupiah(50000))->toBe('lima puluh ribu rupiah');
});

test('negative amounts are not written in words', function () {
    Terbilang::words(-1);
})->throws(InvalidArgumentException::class);

test('rupiah amounts are formatted with dots', function () {
    expect(Rupiah::format(1500000))->toBe('Rp1.500.000')
        ->and(Rupiah::format(0))->toBe('Rp0')
        ->and(Rupiah::format(-5000))->toBe('-Rp5.000');
});
