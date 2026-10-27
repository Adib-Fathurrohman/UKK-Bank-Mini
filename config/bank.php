<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Balance
    |--------------------------------------------------------------------------
    |
    | Default for the "min_balance" setting: a withdrawal is rejected when it
    | would leave the customer's balance below this amount (in Rupiah). The
    | administrator can change it at runtime. See PRD FR-Trx-02.
    |
    */

    'min_balance' => (int) env('BANK_MIN_BALANCE', 10000),

    /*
    |--------------------------------------------------------------------------
    | Cash Denominations
    |--------------------------------------------------------------------------
    |
    | Rupiah notes and coins a teller counts when opening and closing a shift
    | (saldo awal and uang fisik), largest first.
    |
    */

    'denominations' => [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100],

    /*
    |--------------------------------------------------------------------------
    | Withdrawal PIN
    |--------------------------------------------------------------------------
    |
    | After this many wrong PINs in a row, withdrawals from the account are
    | locked for the given number of minutes.
    |
    */

    'pin_max_attempts' => (int) env('BANK_PIN_MAX_ATTEMPTS', 3),

    'pin_lock_minutes' => (int) env('BANK_PIN_LOCK_MINUTES', 15),

];
