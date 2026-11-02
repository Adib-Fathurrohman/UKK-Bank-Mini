<?php

namespace Tests\Support;

use App\Contracts\FirebaseAuth;
use App\Exceptions\CustomerAuthException;
use App\Support\FirebaseIdentity;

/**
 * Stand-in for Firebase Authentication in tests: no network, no project.
 *
 * Register ID tokens with issue(); any other token is invalid. Tokens can be
 * marked revoked or the user disabled, and the whole service can be made
 * unavailable, to exercise each failure path.
 */
class FakeFirebaseAuth implements FirebaseAuth
{
    /** @var array<string, FirebaseIdentity> */
    private array $tokens = [];

    /** @var array<string, true> */
    private array $revoked = [];

    /** @var list<string> */
    public array $disabledUids = [];

    public bool $unavailable = false;

    /**
     * Register an ID token for a Firebase user and return it.
     */
    public function issue(string $uid, ?string $email, bool $emailVerified = true, string $provider = 'google.com'): string
    {
        $token = 'id-token-'.count($this->tokens).'-'.$uid;
        $this->tokens[$token] = new FirebaseIdentity($uid, $email, $emailVerified, $provider);

        return $token;
    }

    public function revoke(string $idToken): void
    {
        $this->revoked[$idToken] = true;
    }

    public function verifyIdToken(string $idToken): FirebaseIdentity
    {
        if ($this->unavailable) {
            throw CustomerAuthException::unavailable();
        }

        $identity = $this->tokens[$idToken] ?? null;

        if (! $identity || isset($this->revoked[$idToken]) || in_array($identity->uid, $this->disabledUids, true)) {
            throw CustomerAuthException::firebaseTokenInvalid();
        }

        if (! in_array($identity->provider, config('services.firebase.providers'), true)) {
            throw CustomerAuthException::providerNotAllowed();
        }

        return $identity;
    }

    public function disableUser(string $uid): void
    {
        if ($this->unavailable) {
            throw CustomerAuthException::unavailable();
        }

        $this->disabledUids[] = $uid;
    }
}
