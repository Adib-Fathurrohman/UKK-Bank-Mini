<?php

namespace App\Services\Push;

use App\Contracts\PushNotifier;
use App\Models\Customer;
use App\Models\CustomerPushToken;
use Closure;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

/**
 * Sends push notifications through Firebase Cloud Messaging (HTTP v1, with
 * the server's service account). Tokens FCM reports as invalid or unknown
 * (app uninstalled, token rotated) are deleted.
 */
class FcmPushNotifier implements PushNotifier
{
    private ?Messaging $messaging = null;

    /**
     * @param  Closure(): Messaging  $makeMessaging  built on first use, so a missing configuration only matters when a push is sent
     */
    public function __construct(private Closure $makeMessaging)
    {
    }

    public function notify(Customer $customer, string $title, string $body, array $data = []): void
    {
        $tokens = $customer->pushTokens()->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data)
            ->withHighestPossiblePriority()
            ->withDefaultSounds();

        try {
            $report = $this->messaging()->sendMulticast($message, $tokens);
        } catch (Throwable $e) {
            // The app still polls, so a missed push only delays the customer.
            Log::warning('Push notification could not be sent.', ['customer_id' => $customer->id, 'error' => $e->getMessage()]);

            return;
        }

        $stale = [...$report->invalidTokens(), ...$report->unknownTokens()];

        if ($stale !== []) {
            CustomerPushToken::whereIn('token', $stale)->delete();
        }
    }

    private function messaging(): Messaging
    {
        return $this->messaging ??= ($this->makeMessaging)();
    }
}
