<?php

declare(strict_types=1);

namespace App\Application\Telemetry;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelemetryEmitter
{
    /**
     * @param array<string, mixed> $properties
     */
    public function emit(
        string $event,
        ?string $userId,
        array $properties = [],
        ?string $idempotencyKey = null,
        string $source = 'api'
    ): void {
        if (! $userId) {
            return;
        }

        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $settings = TenantPushSettings::current()?->telemetry ?? [];
        if (! is_array($settings) || $settings === []) {
            return;
        }

        $idempotencyKey = $idempotencyKey ?: $this->buildIdempotencyKey($event, $userId);
        $tenantId = (string) $tenant->_id;
        $baseProperties = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source' => $source,
            'idempotency_key' => $idempotencyKey,
            ...$properties,
        ];

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
                    properties: $baseProperties,
                    idempotencyKey: $idempotencyKey
                );
                continue;
            }

            if ($type === 'webhook') {
                $this->deliverWebhook(
                    url: (string) ($entry['url'] ?? ''),
                    event: $event,
                    tenantId: $tenantId,
                    userId: $userId,
                    properties: $baseProperties
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
        string $tenantId,
        string $userId,
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
                    'source' => 'api',
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

    private function buildIdempotencyKey(string $event, string $userId): string
    {
        return implode(':', [
            $event,
            $userId,
            (string) Str::uuid(),
        ]);
    }
}
