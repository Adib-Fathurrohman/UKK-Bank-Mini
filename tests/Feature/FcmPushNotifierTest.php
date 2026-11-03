<?php

use App\Contracts\PushNotifier;
use App\Models\Customer;
use App\Models\CustomerPushToken;
use App\Services\Push\FcmPushNotifier;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;

/*
| The FCM adapter. The SDK client is mocked; these tests cover what the
| adapter adds: which tokens it targets, the message, cleaning up tokens FCM
| rejects, and that a failure is logged instead of thrown.
*/

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $session = $this->customer->createToken('customer-portal')->accessToken;

    foreach (['token-a', 'token-b', 'token-c'] as $token) {
        CustomerPushToken::create(['customer_id' => $this->customer->id, 'personal_access_token_id' => $session->id, 'token' => $token, 'platform' => 'android']);
    }
});

function fcmReport(string $token, ?Throwable $error = null): SendReport
{
    $target = MessageTarget::with(MessageTarget::TOKEN, $token);

    return $error ? SendReport::failure($target, $error) : SendReport::success($target, ['name' => 'projects/p/messages/1']);
}

test('it sends one notification to every phone of the customer', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->withArgs(function (CloudMessage $message, array $tokens) {
        $payload = $message->jsonSerialize();

        return $tokens === ['token-a', 'token-b', 'token-c']
            && $payload['notification']['title'] === 'Permintaan penarikan'
            && $payload['data'] === ['type' => 'withdrawal_authorization', 'id' => 'abc'];
    })->andReturn(MulticastSendReport::withItems([fcmReport('token-a'), fcmReport('token-b'), fcmReport('token-c')]));

    (new FcmPushNotifier(fn () => $messaging))->notify($this->customer, 'Permintaan penarikan', 'Isi', ['type' => 'withdrawal_authorization', 'id' => 'abc']);

    expect(CustomerPushToken::count())->toBe(3);
});

test('tokens FCM reports as invalid or unknown are deleted', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->andReturn(MulticastSendReport::withItems([
        fcmReport('token-a'),
        fcmReport('token-b', new InvalidMessage('The registration token is not a valid FCM registration token')),
        fcmReport('token-c', new NotFound('Requested entity was not found.')),
    ]));

    (new FcmPushNotifier(fn () => $messaging))->notify($this->customer, 'T', 'B');

    expect(CustomerPushToken::pluck('token')->all())->toBe(['token-a']);
});

test('a customer without phones causes no FCM call', function () {
    CustomerPushToken::query()->delete();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('sendMulticast');

    (new FcmPushNotifier(fn () => $messaging))->notify($this->customer, 'T', 'B');
});

test('an FCM outage or a missing configuration is logged, not thrown', function (Closure $makeMessaging) {
    Log::spy();

    (new FcmPushNotifier($makeMessaging))->notify($this->customer, 'T', 'B');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'Push notification'))->once();
    expect(CustomerPushToken::count())->toBe(3);
})->with([
    'outage' => [function () {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->andThrow(new ApiConnectionFailed('timeout'));

        return fn () => $messaging;
    }],
    'no credentials' => [fn () => fn () => throw new RuntimeException('Firebase service account file not found')],
]);

test('the real binding without credentials does not break anything', function () {
    config(['services.firebase.credentials' => 'storage/app/private/missing.json']);
    app()->forgetInstance(PushNotifier::class);

    app(PushNotifier::class)->notify($this->customer, 'T', 'B');
})->throwsNoExceptions();
