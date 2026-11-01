<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bank and School Name
    |--------------------------------------------------------------------------
    |
    | Printed on receipts and reports.
    |
    */

    'name' => env('BANK_NAME', 'Bank Mini Sekolah'),

    'school' => env('BANK_SCHOOL', 'SMK'),

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
    | Customer PIN
    |--------------------------------------------------------------------------
    |
    | After this many wrong PINs in a row (at login, withdrawal approval, a
    | terminal withdrawal or PIN change) the PIN is locked everywhere for the
    | given number of minutes.
    |
    */

    'pin_max_attempts' => (int) env('BANK_PIN_MAX_ATTEMPTS', 3),

    'pin_lock_minutes' => (int) env('BANK_PIN_LOCK_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Withdrawal Authorization (customer approves in the mobile app)
    |--------------------------------------------------------------------------
    |
    | How long the customer has to approve a teller's withdrawal request, and
    | how long the teller then has to complete it, in seconds.
    |
    */

    'withdrawal_authorization_pending_seconds' => (int) env('BANK_WITHDRAWAL_AUTH_PENDING_SECONDS', 120),

    'withdrawal_authorization_approved_seconds' => (int) env('BANK_WITHDRAWAL_AUTH_APPROVED_SECONDS', 120),

];
