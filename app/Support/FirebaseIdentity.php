<?php

namespace App\Support;

/**
 * The claims of a verified Firebase ID token that the backend relies on.
 * Only ever built from a verified token, never from request input.
 */
final readonly class FirebaseIdentity
{
    public function __construct(
        public string $uid,
        public ?string $email,
        public bool $emailVerified,
        /** "password" (email/password) or "google.com". */
        public string $provider,
    ) {
    }

    public function normalizedEmail(): ?string
    {
        return $this->email === null ? null : mb_strtolower(trim($this->email));
    }
}
