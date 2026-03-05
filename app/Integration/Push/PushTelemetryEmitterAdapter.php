<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Application\Telemetry\Contracts\TelemetryEmitterContract as HostTelemetryEmitterContract;
use Belluga\PushHandler\Contracts\PushTelemetryEmitterContract;

class PushTelemetryEmitterAdapter implements PushTelemetryEmitterContract
{
    public function __construct(
        private readonly HostTelemetryEmitterContract $telemetry
    ) {
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     */
    public function emit(
        string $event,
        string $userId,
        array $properties = [],
        ?string $idempotencyKey = null,
        string $source = 'push',
        array $context = [],
    ): void {
        $this->telemetry->emit(
            event: $event,
            userId: $userId,
            properties: $properties,
            idempotencyKey: $idempotencyKey,
            source: $source,
            context: $context,
        );
    }
}

