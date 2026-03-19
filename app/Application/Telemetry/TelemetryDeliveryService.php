<?php

declare(strict_types=1);

namespace App\Application\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class TelemetryDeliveryService
{
    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<int, array<string, mixed>>  $trackers
     */
    public function deliver(array $envelope, array $trackers): void
    {
        $event = (string) ($envelope['event'] ?? '');
        if ($event === '') {
            return;
        }

        foreach ($trackers as $tracker) {
            if (! is_array($tracker)) {
                continue;
            }

            if (! $this->shouldTrack($tracker, $event)) {
                continue;
            }

            $type = $tracker['type'] ?? null;
            if ($type === 'mixpanel') {
                $this->deliverMixpanel($tracker, $envelope);

                continue;
            }

            if ($type === 'webhook') {
                $this->deliverWebhook($tracker, $envelope);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $tracker
     */
    private function shouldTrack(array $tracker, string $event): bool
    {
        $trackAll = filter_var($tracker['track_all'] ?? false, FILTER_VALIDATE_BOOL);
        if ($trackAll) {
            return true;
        }

        $events = $tracker['events'] ?? [];
        if (! is_array($events)) {
            return false;
        }

        return in_array($event, $events, true);
    }

    /**
     * @param  array<string, mixed>  $tracker
     * @param  array<string, mixed>  $envelope
     */
    private function deliverMixpanel(array $tracker, array $envelope): void
    {
        $token = (string) ($tracker['token'] ?? '');
        if ($token === '') {
            return;
        }

        $actor = is_array($envelope['actor'] ?? null) ? $envelope['actor'] : [];
        $metadata = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];
        $occurredAt = (string) ($envelope['occurred_at'] ?? now()->toISOString());
        $timestamp = CarbonImmutable::parse($occurredAt)->timestamp;

        $payload = [
            'event' => (string) ($envelope['event'] ?? ''),
            'properties' => array_filter([
                'token' => $token,
                'distinct_id' => (string) ($actor['id'] ?? ''),
                '$insert_id' => (string) ($envelope['idempotency_key'] ?? ''),
                'time' => $timestamp,
                ...$metadata,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];

        try {
            Http::asJson()->post('https://api.mixpanel.com/track', $payload);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $tracker
     * @param  array<string, mixed>  $envelope
     */
    private function deliverWebhook(array $tracker, array $envelope): void
    {
        $url = (string) ($tracker['url'] ?? '');
        if ($url === '') {
            return;
        }

        $actor = is_array($envelope['actor'] ?? null) ? $envelope['actor'] : [];
        $metadata = is_array($envelope['metadata'] ?? null) ? $envelope['metadata'] : [];

        $payload = [
            'type' => 'event',
            'timestamp' => (string) ($envelope['occurred_at'] ?? now()->toISOString()),
            'context' => [
                'app' => [
                    'environment' => app()->environment(),
                    'source' => (string) ($envelope['source'] ?? 'api'),
                ],
                'tenant' => [
                    'id' => (string) ($envelope['tenant_id'] ?? ''),
                ],
                'user' => [
                    'id' => (string) ($actor['id'] ?? ''),
                ],
            ],
            'payload' => [
                'event' => (string) ($envelope['event'] ?? ''),
                'properties' => $metadata,
                'envelope' => $envelope,
            ],
        ];

        try {
            Http::asJson()->post($url, $payload);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
