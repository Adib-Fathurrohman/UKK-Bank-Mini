<?php

namespace App\Providers;

use App\Contracts\PushNotifier;
use App\Models\Customer;
use App\Services\Push\FcmPushNotifier;
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
        // Firebase is only used for push notifications (FCM) to the customer app.
        $this->app->singleton(PushNotifier::class, fn () => new FcmPushNotifier(function () {
            $credentials = (string) config('services.firebase.credentials');
            $path = str_starts_with($credentials, '/') ? $credentials : base_path($credentials);

            if ($credentials === '' || ! is_file($path)) {
                throw new RuntimeException('Firebase service account file not found: set FIREBASE_CREDENTIALS.');
            }

            return (new Factory)
                ->withServiceAccount($path)
                ->withProjectId((string) config('services.firebase.project_id'))
                // The service account's access token is cached on disk between requests.
                ->withAuthTokenCache(new FileSystemCacheItemPool(storage_path('framework/cache/firebase')))
                ->createMessaging();
        }));
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
        // limited per account identifier and IP address (the PIN lock is the
        // real protection). Per identifier, so a class behind one school Wi-Fi
        // address does not block itself.
        RateLimiter::for('login', function (Request $request) {
            $identifier = (string) ($request->input('username') ?? $request->input('identifier'));

            return Limit::perMinute(5)->by(Str::lower($identifier).'|'.$request->ip());
        });

        // Registering and removing push tokens, per customer.
        RateLimiter::for('customer-push-token', fn (Request $request) => Limit::perMinute(10)->by('customer:'.$request->user()->getAuthIdentifier()));

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
