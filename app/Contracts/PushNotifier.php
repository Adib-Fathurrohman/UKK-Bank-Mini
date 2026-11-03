<?php

namespace App\Contracts;

use App\Models\Customer;

/**
 * Push notifications to the customer's phones.
 *
 * Implemented by App\Services\Push\FcmPushNotifier (Firebase Cloud
 * Messaging); tests use an in-memory fake. Push is a convenience on top of
 * polling: a failure is reported, never thrown to the caller.
 */
interface PushNotifier
{
    /**
     * @param  array<string, string>  $data  delivered to the app with the notification
     */
    public function notify(Customer $customer, string $title, string $body, array $data = []): void;
}
