<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\PhoneOtpWebhookDeliveryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpWebhookDeliveryServiceTest extends TestCase
{
    public function test_deliver_posts_json_payload_to_configured_webhook(): void
    {
        Http::fake([
            'https://integrations.example/otp' => Http::response(['ok' => true]),
        ]);

        app(PhoneOtpWebhookDeliveryService::class)->deliver(
            'https://integrations.example/otp',
            [
                'type' => 'phone_otp.challenge',
                'channel' => 'whatsapp',
                'phone' => '+5527999990000',
                'code' => '123456',
                'challenge_id' => 'challenge-1',
            ],
        );

        Http::assertSent(
            fn (Request $request): bool => $request->url() === 'https://integrations.example/otp'
                && $request['type'] === 'phone_otp.challenge'
                && $request['channel'] === 'whatsapp'
                && $request['phone'] === '+5527999990000'
                && $request['code'] === '123456'
                && $request['challenge_id'] === 'challenge-1'
        );
    }

    public function test_deliver_posts_to_webhook_url_with_query_string_unchanged(): void
    {
        $webhookUrl = 'https://n8ntech.unifast.com.br/webhook/otp?channel=whatsapp';

        Http::fake([
            $webhookUrl => Http::response(['ok' => true]),
        ]);

        app(PhoneOtpWebhookDeliveryService::class)->deliver(
            $webhookUrl,
            [
                'type' => 'phone_otp.challenge',
                'channel' => 'whatsapp',
                'phone' => '+5527999990010',
                'code' => '654321',
                'challenge_id' => 'challenge-query-string',
            ],
        );

        Http::assertSent(
            fn (Request $request): bool => $request->url() === $webhookUrl
                && $request['type'] === 'phone_otp.challenge'
                && $request['channel'] === 'whatsapp'
                && $request['phone'] === '+5527999990010'
                && $request['code'] === '654321'
                && $request['challenge_id'] === 'challenge-query-string'
        );
    }
}
