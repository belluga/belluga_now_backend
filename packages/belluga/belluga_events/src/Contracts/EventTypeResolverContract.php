<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventTypeResolverContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function resolveById(string $eventTypeId): ?array;
}
