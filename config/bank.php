<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Balance
    |--------------------------------------------------------------------------
    |
    | A withdrawal is rejected when it would leave the customer's balance
    | below this amount (in Rupiah). See PRD FR-Trx-02.
    |
    */

    'min_balance' => (int) env('BANK_MIN_BALANCE', 10000),

];
