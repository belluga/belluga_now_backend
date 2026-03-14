<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteTelemetryEmitterContract
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    public function emit(
        string $event,
        string $userId,
        array $properties = [],
        ?string $idempotencyKey = null,
        string $source = 'invite',
        array $context = [],
    ): void;
}
