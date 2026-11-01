<?php

use App\Services\WithdrawalAuthorizationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tokens expire after SANCTUM_EXPIRATION minutes; remove the dead rows daily.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Withdrawal requests are also expired lazily when read; this keeps abandoned
// ones from staying "pending" in the database.
Artisan::command('bank:expire-withdrawal-authorizations', function (WithdrawalAuthorizationService $authorizations) {
    $this->info('Expired: '.$authorizations->expireOverdue());
})->purpose('Mark overdue withdrawal authorizations as expired');

Schedule::command('bank:expire-withdrawal-authorizations')->everyMinute();
