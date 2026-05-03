<?php

declare(strict_types=1);

namespace App\Application\Auth;

use Illuminate\Support\Facades\Http;

class PhoneOtpWebhookDeliveryService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliver(string $webhookUrl, array $payload): void
    {
        Http::timeout(10)
            ->retry(2, 250)
            ->asJson()
            ->post($webhookUrl, $payload)
            ->throw();
    }
}
