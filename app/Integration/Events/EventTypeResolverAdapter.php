<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Events\EventTypeRegistryService;
use Belluga\Events\Contracts\EventTypeResolverContract;

class EventTypeResolverAdapter implements EventTypeResolverContract
{
    public function __construct(
        private readonly EventTypeRegistryService $registryService,
    ) {
    }

    public function resolveById(string $eventTypeId): ?array
    {
        return $this->registryService->findById($eventTypeId);
    }
}
