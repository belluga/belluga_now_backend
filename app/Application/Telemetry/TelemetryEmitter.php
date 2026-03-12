<?php

declare(strict_types=1);

namespace App\Application\Telemetry;

use App\Application\Telemetry\Contracts\TelemetryEmitterContract;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;

class TelemetryEmitter implements TelemetryEmitterContract
{
    public function __construct(
        private readonly TelemetrySettingsKernelBridge $telemetrySettings
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    public function emit(
        string $event,
        ?string $userId,
        array $properties = [],
        ?string $idempotencyKey = null,
        string $source = 'api',
        array $context = []
    ): void {
        if (! $userId) {
            return;
        }

        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $settings = $this->telemetrySettings->currentTelemetryConfig();
        $trackers = $settings['trackers'] ?? [];
        if (! is_array($trackers) || $trackers === []) {
            return;
        }

        $idempotencyKey = $idempotencyKey ?: $this->buildIdempotencyKey($event, $userId);
        $envelope = $this->buildEnvelope(
            event: $event,
            userId: $userId,
            tenantId: (string) $tenant->_id,
            source: $source,
            idempotencyKey: $idempotencyKey,
            properties: $properties,
            context: $context,
        );

        DeliverTelemetryEventJob::dispatch($envelope, $trackers);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildEnvelope(
        string $event,
        string $userId,
        string $tenantId,
        string $source,
        string $idempotencyKey,
        array $properties,
        array $context
    ): array {
        $actor = $this->resolveEnvelopeEntity(
            candidate: $context['actor'] ?? null,
            defaultType: 'user',
            defaultId: $userId,
        );

        return [
            'event' => $event,
            'occurred_at' => now()->toISOString(),
            'tenant_id' => $tenantId,
            'source' => $source,
            'idempotency_key' => $idempotencyKey,
            'visibility' => is_string($context['visibility'] ?? null) && $context['visibility'] !== ''
                ? $context['visibility']
                : 'tenant',
            'actor' => $actor,
            'object' => $this->resolveEnvelopeEntity(
                candidate: $context['object'] ?? null,
                defaultType: 'event',
                defaultId: null,
            ),
            'target' => $this->resolveEnvelopeEntity(
                candidate: $context['target'] ?? null,
                defaultType: 'user',
                defaultId: $userId,
            ),
            'metadata' => [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                ...$properties,
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveEnvelopeEntity(mixed $candidate, string $defaultType, ?string $defaultId): ?array
    {
        if (! is_array($candidate)) {
            return $defaultId !== null && $defaultId !== ''
                ? ['type' => $defaultType, 'id' => $defaultId]
                : null;
        }

        $type = $candidate['type'] ?? $defaultType;
        $id = $candidate['id'] ?? $defaultId;

        if (! is_string($type) || $type === '' || ! is_string($id) || $id === '') {
            return null;
        }

        return ['type' => $type, 'id' => $id];
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
