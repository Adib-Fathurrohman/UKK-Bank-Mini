<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A 6-digit PIN only has a million combinations, so login attempts are
        // limited per account identifier and IP address.
        RateLimiter::for('login', function (Request $request) {
            $identifier = (string) ($request->input('username') ?? $request->input('identifier'));

            return Limit::perMinute(5)->by(Str::lower($identifier).'|'.$request->ip());
        });

        // Withdrawal authorizations. Named limiters keep separate counters per
        // endpoint group and actor (tellers and customers share numeric ids).
        $perActor = fn (Request $request) => class_basename($request->user()).':'.$request->user()->getAuthIdentifier();

        RateLimiter::for('withdrawal-authorization-create', fn (Request $request) => Limit::perMinute(10)->by($perActor($request)));
        RateLimiter::for('withdrawal-authorization-poll', fn (Request $request) => Limit::perMinute(120)->by($perActor($request)));
        RateLimiter::for('withdrawal-authorization-decide', fn (Request $request) => Limit::perMinute(5)->by($perActor($request)));
    }
}
