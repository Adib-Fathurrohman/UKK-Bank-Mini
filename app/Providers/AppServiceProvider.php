<?php

namespace App\Providers;

use App\Contracts\FirebaseAuth;
use App\Models\Customer;
use App\Services\Firebase\KreaitFirebaseAuth;
use Google\Auth\Cache\FileSystemCacheItemPool;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FirebaseAuth::class, fn () => new KreaitFirebaseAuth(
            function () {
                $credentials = (string) config('services.firebase.credentials');
                $path = str_starts_with($credentials, '/') ? $credentials : base_path($credentials);

                if ($credentials === '' || ! is_file($path)) {
                    throw new RuntimeException('Firebase service account file not found: set FIREBASE_CREDENTIALS.');
                }

                // Google's public keys and the service account's access token are cached on disk between requests.
                $cache = new FileSystemCacheItemPool(storage_path('framework/cache/firebase'));

                return (new Factory)
                    ->withServiceAccount($path)
                    ->withProjectId((string) config('services.firebase.project_id'))
                    ->withVerifierCache($cache)
                    ->withAuthTokenCache($cache)
                    ->createAuth();
            },
            config('services.firebase.project_id'),
            config('services.firebase.providers'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Customer sessions last much longer than staff ones (CUSTOMER_SESSION_MINUTES):
        // their tokens carry their own expires_at, which replaces the global
        // SANCTUM_EXPIRATION. Staff tokens and older customer tokens keep the global rule.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if ($token->tokenable_type === (new Customer)->getMorphClass() && $token->expires_at) {
                return $token->expires_at->isFuture();
            }

            return $isValid;
        });

        // A 6-digit PIN only has a million combinations, so login attempts are
        // limited per account identifier and IP address. A Firebase ID token
        // cannot be guessed, so those logins are only limited per IP, generously
        // enough for a class behind one school Wi-Fi address.
        RateLimiter::for('login', function (Request $request) {
            if ($request->filled('id_token') && ! $request->filled('username') && ! $request->filled('identifier')) {
                return Limit::perMinute(30)->by('firebase|'.$request->ip());
            }

            $identifier = (string) ($request->input('username') ?? $request->input('identifier'));

            return Limit::perMinute(5)->by(Str::lower($identifier).'|'.$request->ip());
        });

        // Activation links: opening and completing them, per IP (tokens are 256-bit random, so this is about abuse, not guessing).
        RateLimiter::for('customer-activation', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // New invitation emails: a customer asking for one (after NIS + PIN), and an administrator resending one.
        RateLimiter::for('customer-activation-resend', fn (Request $request) => Limit::perHour(3)->by(Str::lower((string) $request->input('identifier'))));
        RateLimiter::for('customer-invitation', function (Request $request) {
            // Throttling runs before route model binding, so the parameter may still be the raw id.
            $customer = $request->route('customer');

            return Limit::perHour(5)->by('customer:'.(is_object($customer) ? $customer->getKey() : $customer));
        });

        // Withdrawal authorizations. Named limiters keep separate counters per
        // endpoint group and actor (tellers and customers share numeric ids).
        $perActor = fn (Request $request) => class_basename($request->user()).':'.$request->user()->getAuthIdentifier();

        RateLimiter::for('withdrawal-authorization-create', fn (Request $request) => Limit::perMinute(10)->by($perActor($request)));
        RateLimiter::for('withdrawal-authorization-poll', fn (Request $request) => Limit::perMinute(120)->by($perActor($request)));
        RateLimiter::for('withdrawal-authorization-decide', fn (Request $request) => Limit::perMinute(5)->by($perActor($request)));

        // Unlocking the app with the PIN during a session (the PIN lock is the real brute-force protection).
        RateLimiter::for('customer-unlock', fn (Request $request) => Limit::perMinute(5)->by($perActor($request)));
    }
}
