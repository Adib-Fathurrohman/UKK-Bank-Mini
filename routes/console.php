<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tokens expire after SANCTUM_EXPIRATION minutes; remove the dead rows daily.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
