<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushTelemetryEmitterContract
{
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
    ): void;
}

