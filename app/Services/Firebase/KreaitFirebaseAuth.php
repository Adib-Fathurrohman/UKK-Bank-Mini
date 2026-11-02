<?php

namespace App\Services\Firebase;

use App\Contracts\FirebaseAuth;
use App\Exceptions\CustomerAuthException;
use App\Support\FirebaseIdentity;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\FirebaseException;
use Lcobucci\JWT\UnencryptedToken;
use SensitiveParameter;
use Throwable;

/**
 * Firebase Authentication through the Firebase Admin SDK (kreait/firebase-php).
 *
 * verifyIdToken() checks, in order:
 * 1. signature, expiry, issued-at and auth_time (SDK, against Google's public keys);
 * 2. audience and issuer = the configured project (SDK, repeated here explicitly);
 * 3. the sign-in provider is allowed (password or google.com by default);
 * 4. the user still exists and is not disabled, and the token was not issued
 *    before the user's sessions were revoked (one getUser() call; the same rule
 *    as the SDK's checkIfRevoked, which does not look at "disabled").
 *
 * The revocation lookup costs one request to Firebase, so it runs only where
 * a Sanctum token is issued (login and activation), not on every API request.
 */
class KreaitFirebaseAuth implements FirebaseAuth
{
    private ?Auth $auth = null;

    /**
     * @param  Closure(): Auth  $makeAuth  builds the SDK client on first use, so a missing configuration only fails Firebase requests
     * @param  list<string>  $allowedProviders
     */
    public function __construct(
        private Closure $makeAuth,
        private ?string $projectId,
        private array $allowedProviders,
    ) {
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): FirebaseIdentity
    {
        $auth = $this->auth();

        try {
            $token = $auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            // Also raised when Google's public keys cannot be fetched; the log tells them apart.
            Log::info('Firebase ID token rejected: '.$e->getMessage());

            throw CustomerAuthException::firebaseTokenInvalid();
        } catch (Throwable $e) {
            report($e);

            throw CustomerAuthException::unavailable();
        }

        if (! $token instanceof UnencryptedToken
            || ! $token->isPermittedFor((string) $this->projectId)
            || ! $token->hasBeenIssuedBy('https://securetoken.google.com/'.$this->projectId)) {
            throw CustomerAuthException::firebaseTokenInvalid();
        }

        $claims = $token->claims();
        $provider = $claims->get('firebase')['sign_in_provider'] ?? null;

        if (! in_array($provider, $this->allowedProviders, true)) {
            throw CustomerAuthException::providerNotAllowed();
        }

        $uid = (string) $claims->get('sub');

        try {
            $user = $auth->getUser($uid);
        } catch (UserNotFound) {
            throw CustomerAuthException::firebaseTokenInvalid();
        } catch (FirebaseException $e) {
            report($e);

            throw CustomerAuthException::unavailable();
        }

        if ($user->disabled || $this->sessionRevoked($claims->get('auth_time'), $user->tokensValidAfterTime)) {
            throw CustomerAuthException::firebaseTokenInvalid();
        }

        return new FirebaseIdentity(
            uid: $uid,
            email: $claims->get('email'),
            emailVerified: $claims->get('email_verified') === true,
            provider: $provider,
        );
    }

    public function disableUser(string $uid): void
    {
        $auth = $this->auth();

        try {
            $auth->disableUser($uid);
            $auth->revokeRefreshTokens($uid);
        } catch (UserNotFound) {
            // Already deleted in the Firebase console: nothing left to disable.
        } catch (Throwable $e) {
            report($e);

            throw CustomerAuthException::unavailable();
        }
    }

    /**
     * A token whose sign-in happened before "tokens valid after" was revoked
     * (by revokeRefreshTokens, a password change or a console action).
     */
    private function sessionRevoked(mixed $authTime, ?DateTimeImmutable $validSince): bool
    {
        if ($validSince === null) {
            return false;
        }

        $authenticatedAt = $authTime instanceof DateTimeImmutable ? $authTime->getTimestamp() : (int) $authTime;

        return $authenticatedAt < $validSince->getTimestamp();
    }

    private function auth(): Auth
    {
        if ($this->auth) {
            return $this->auth;
        }

        if (! $this->projectId) {
            Log::error('Firebase is not configured: set FIREBASE_PROJECT_ID and FIREBASE_CREDENTIALS.');

            throw CustomerAuthException::unavailable();
        }

        try {
            return $this->auth = ($this->makeAuth)();
        } catch (Throwable $e) {
            report($e);

            throw CustomerAuthException::unavailable();
        }
    }
}
