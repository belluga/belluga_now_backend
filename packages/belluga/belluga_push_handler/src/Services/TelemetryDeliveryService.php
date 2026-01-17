<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Facades\Http;

class TelemetryDeliveryService
{
    public function deliverInviteReceived(
        PushMessage $message,
        string $userId,
        string $messageInstanceId
    ): void {
        $this->deliverEvent(
            event: 'invite_received',
            message: $message,
            userId: $userId,
            messageInstanceId: $messageInstanceId
        );
    }

    private function deliverEvent(
        string $event,
        PushMessage $message,
        string $userId,
        string $messageInstanceId
    ): void {
        $settings = TenantPushSettings::current()?->telemetry ?? [];
        if (! is_array($settings) || $settings === []) {
            return;
        }

        $tenantId = (string) (Tenant::current()?->_id ?? '');
        $idempotencyKey = $this->buildIdempotencyKey($event, $message, $userId, $messageInstanceId);
        $properties = [
            'user_id' => $userId,
            'push_message_id' => (string) $message->_id,
            'message_instance_id' => $messageInstanceId,
            'push_type' => (string) ($message->type ?? ''),
            'source' => 'push_delivery',
            'idempotency_key' => $idempotencyKey,
        ];
        if ($tenantId !== '') {
            $properties['tenant_id'] = $tenantId;
        }

        foreach ($settings as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $trackAll = filter_var($entry['track_all'] ?? false, FILTER_VALIDATE_BOOL);
            $events = $entry['events'] ?? [];
            if (! $trackAll && (! is_array($events) || ! in_array($event, $events, true))) {
                continue;
            }

            $type = $entry['type'] ?? null;
            if ($type === 'mixpanel') {
                $this->deliverMixpanel(
                    token: (string) ($entry['token'] ?? ''),
                    event: $event,
                    userId: $userId,
                    properties: $properties,
                    idempotencyKey: $idempotencyKey
                );
                continue;
            }

            if ($type === 'webhook') {
                $this->deliverWebhook(
                    url: (string) ($entry['url'] ?? ''),
                    event: $event,
                    userId: $userId,
                    tenantId: $tenantId,
                    properties: $properties
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function deliverMixpanel(
        string $token,
        string $event,
        string $userId,
        array $properties,
        string $idempotencyKey
    ): void {
        if ($token === '') {
            return;
        }

        $payload = [
            'event' => $event,
            'properties' => array_filter([
                'token' => $token,
                'distinct_id' => $userId,
                '$insert_id' => $idempotencyKey,
                'time' => now()->timestamp,
                ...$properties,
            ], static fn ($value) => $value !== null && $value !== ''),
        ];

        try {
            Http::asJson()->post('https://api.mixpanel.com/track', $payload);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function deliverWebhook(
        string $url,
        string $event,
        string $userId,
        string $tenantId,
        array $properties
    ): void {
        if ($url === '') {
            return;
        }

        $payload = [
            'type' => 'event',
            'timestamp' => now()->toISOString(),
            'context' => [
                'app' => [
                    'environment' => app()->environment(),
                    'source' => 'push_handler',
                ],
                'tenant' => [
                    'id' => $tenantId,
                ],
                'user' => [
                    'id' => $userId,
                ],
            ],
            'payload' => [
                'event' => $event,
                'properties' => $properties,
            ],
        ];

        try {
            Http::asJson()->post($url, $payload);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function buildIdempotencyKey(
        string $event,
        PushMessage $message,
        string $userId,
        string $messageInstanceId
    ): string {
        return implode(':', [
            $event,
            (string) $message->_id,
            $messageInstanceId,
            $userId,
        ]);
    }
}
