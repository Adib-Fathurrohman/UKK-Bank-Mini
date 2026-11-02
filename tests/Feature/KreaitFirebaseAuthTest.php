<?php

use App\Exceptions\CustomerAuthException;
use App\Services\Firebase\KreaitFirebaseAuth;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\ApiConnectionFailed;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\UnencryptedToken;

/*
| The adapter around the Firebase Admin SDK. The SDK client is mocked (its
| signature checks are the SDK's own, covered by its tests); these tests cover
| what the adapter adds: project, provider, disabled and revocation checks,
| and how failures are reported.
*/

const PROJECT = 'bank-mini-test';

/**
 * A decoded ID token as the SDK returns it after verifying the signature.
 */
function idTokenClaims(array $overrides = []): UnencryptedToken
{
    $claims = array_merge([
        'iss' => 'https://securetoken.google.com/'.PROJECT,
        'aud' => PROJECT,
        'sub' => 'uid-1',
        'email' => 'budi@example.test',
        'email_verified' => true,
        'auth_time' => now()->subMinute()->getTimestamp(),
        'firebase' => ['sign_in_provider' => 'google.com'],
    ], $overrides);

    $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->issuedBy($claims['iss'])
        ->permittedFor($claims['aud'])
        ->relatedTo($claims['sub']);

    foreach (array_diff_key($claims, array_flip(['iss', 'aud', 'sub'])) as $name => $value) {
        $builder = $builder->withClaim($name, $value);
    }

    return $builder->getToken(new Sha256, InMemory::plainText(str_repeat('k', 32)));
}

function firebaseUser(array $overrides = []): UserRecord
{
    return UserRecord::fromResponseData(array_merge([
        'localId' => 'uid-1',
        'email' => 'budi@example.test',
        'emailVerified' => true,
        'createdAt' => '1700000000000',
    ], $overrides));
}

function adapter(Auth $sdk, ?string $projectId = PROJECT): KreaitFirebaseAuth
{
    return new KreaitFirebaseAuth(fn () => $sdk, $projectId, ['password', 'google.com']);
}

function expectAuthError(Closure $call, string $code): void
{
    try {
        $call();
    } catch (CustomerAuthException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }

    test()->fail("Expected CustomerAuthException [{$code}].");
}

test('a valid token becomes a trusted identity', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->once()->with('raw-token')->andReturn(idTokenClaims());
    $sdk->shouldReceive('getUser')->once()->with('uid-1')->andReturn(firebaseUser());

    $identity = adapter($sdk)->verifyIdToken('raw-token');

    expect($identity->uid)->toBe('uid-1')
        ->and($identity->email)->toBe('budi@example.test')
        ->and($identity->emailVerified)->toBeTrue()
        ->and($identity->provider)->toBe('google.com');
});

test('an unverified email is passed on as unverified', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims(['email_verified' => false, 'firebase' => ['sign_in_provider' => 'password']]));
    $sdk->shouldReceive('getUser')->andReturn(firebaseUser());

    expect(adapter($sdk)->verifyIdToken('raw-token')->emailVerified)->toBeFalse();
});

test('a token the SDK cannot verify (bad signature, expired, malformed) is invalid', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andThrow(new FailedToVerifyToken('The token is expired.'));
    $sdk->shouldNotReceive('getUser');

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'firebase_token_invalid');
});

test('a token for another Firebase project is invalid', function (array $claims) {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims($claims));
    $sdk->shouldNotReceive('getUser');

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'firebase_token_invalid');
})->with([
    'audience' => [['aud' => 'other-project']],
    'issuer' => [['iss' => 'https://securetoken.google.com/other-project']],
]);

test('only the configured sign-in providers are accepted', function (string $provider) {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims(['firebase' => ['sign_in_provider' => $provider]]));

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'provider_not_allowed');
})->with(['anonymous', 'phone', 'custom', 'facebook.com']);

test('a disabled Firebase user is refused', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims());
    $sdk->shouldReceive('getUser')->andReturn(firebaseUser(['disabled' => true]));

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'firebase_token_invalid');
});

test('a token signed in before the sessions were revoked is refused', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims(['auth_time' => now()->subHour()->getTimestamp()]));
    $sdk->shouldReceive('getUser')->andReturn(firebaseUser(['validSince' => (string) now()->subMinutes(5)->getTimestamp()]));

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'firebase_token_invalid');
});

test('a token signed in after a revocation is fine', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims(['auth_time' => now()->subMinute()->getTimestamp()]));
    $sdk->shouldReceive('getUser')->andReturn(firebaseUser(['validSince' => (string) now()->subHour()->getTimestamp()]));

    expect(adapter($sdk)->verifyIdToken('raw-token')->uid)->toBe('uid-1');
});

test('a deleted Firebase user is refused', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims());
    $sdk->shouldReceive('getUser')->andThrow(new UserNotFound('No user'));

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'firebase_token_invalid');
});

test('a Firebase outage is reported as unavailable, not as a bad login', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('verifyIdToken')->andReturn(idTokenClaims());
    $sdk->shouldReceive('getUser')->andThrow(new ApiConnectionFailed('timeout'));

    expectAuthError(fn () => adapter($sdk)->verifyIdToken('raw-token'), 'auth_unavailable');
});

test('a missing configuration is reported as unavailable', function () {
    expectAuthError(fn () => adapter(Mockery::mock(Auth::class), null)->verifyIdToken('raw-token'), 'auth_unavailable');

    $broken = new KreaitFirebaseAuth(fn () => throw new RuntimeException('no credentials file'), PROJECT, ['google.com']);
    expectAuthError(fn () => $broken->verifyIdToken('raw-token'), 'auth_unavailable');
});

test('the real binding without credentials is unavailable instead of crashing', function () {
    config(['services.firebase.project_id' => PROJECT, 'services.firebase.credentials' => 'storage/app/private/missing.json']);
    app()->forgetInstance(App\Contracts\FirebaseAuth::class);

    expectAuthError(fn () => app(App\Contracts\FirebaseAuth::class)->verifyIdToken('raw-token'), 'auth_unavailable');
});

test('disabling a user also revokes their refresh tokens', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('disableUser')->once()->with('uid-1')->andReturn(firebaseUser(['disabled' => true]));
    $sdk->shouldReceive('revokeRefreshTokens')->once()->with('uid-1');

    adapter($sdk)->disableUser('uid-1');
});

test('disabling a user that no longer exists in Firebase is not an error', function () {
    $sdk = Mockery::mock(Auth::class);
    $sdk->shouldReceive('disableUser')->andThrow(new UserNotFound('No user'));

    adapter($sdk)->disableUser('uid-1');
})->throwsNoExceptions();
