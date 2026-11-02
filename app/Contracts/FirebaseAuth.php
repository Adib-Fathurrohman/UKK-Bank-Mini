<?php

namespace App\Contracts;

use App\Exceptions\CustomerAuthException;
use App\Support\FirebaseIdentity;

/**
 * Server-side access to Firebase Authentication for the customer login.
 *
 * Implemented by App\Services\Firebase\KreaitFirebaseAuth (Firebase Admin
 * SDK). Tests bind a fake, so the normal test suite needs no Firebase project.
 */
interface FirebaseAuth
{
    /**
     * Verify a Firebase ID token from the customer app and return the trusted
     * identity in it.
     *
     * The token must be signed by Google for the configured project, not be
     * expired or revoked, belong to an enabled user, and come from an allowed
     * sign-in provider.
     *
     * @throws CustomerAuthException firebase_token_invalid, provider_not_allowed or auth_unavailable
     */
    public function verifyIdToken(string $idToken): FirebaseIdentity;

    /**
     * Disable the Firebase user and revoke their refresh tokens, so the app
     * cannot get new ID tokens for it.
     *
     * @throws CustomerAuthException auth_unavailable
     */
    public function disableUser(string $uid): void;
}
