<?php

namespace Tests\Support;

use App\Contracts\PushNotifier;
use App\Models\Customer;

/**
 * Stand-in for FCM in tests: records every notification instead of sending it.
 */
class FakePushNotifier implements PushNotifier
{
    /** @var list<array{customer_id: int, title: string, body: string, data: array<string, string>}> */
    public array $sent = [];

    public bool $failing = false;

    public function notify(Customer $customer, string $title, string $body, array $data = []): void
    {
        if ($this->failing) {
            throw new \RuntimeException('FCM unreachable');
        }

        $this->sent[] = ['customer_id' => $customer->id, 'title' => $title, 'body' => $body, 'data' => $data];
    }
}
