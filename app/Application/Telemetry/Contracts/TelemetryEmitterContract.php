<?php

declare(strict_types=1);

namespace App\Application\Telemetry\Contracts;

interface TelemetryEmitterContract
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     */
    public function emit(
        string $event,
        ?string $userId,
        array $properties = [],
        ?string $idempotencyKey = null,
        string $source = 'api',
        array $context = []
    ): void;
}

