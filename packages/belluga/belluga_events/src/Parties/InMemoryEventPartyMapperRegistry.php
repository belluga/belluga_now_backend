<?php

declare(strict_types=1);

namespace Belluga\Events\Parties;

use Belluga\Events\Contracts\EventPartyMapperContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use RuntimeException;

class InMemoryEventPartyMapperRegistry implements EventPartyMapperRegistryContract
{
    /**
     * @var array<string, EventPartyMapperContract>
     */
    private array $mappers = [];

    public function register(EventPartyMapperContract $mapper): void
    {
        $partyType = $mapper->partyType();
        if (isset($this->mappers[$partyType])) {
            throw new RuntimeException("Duplicate event party mapper [{$partyType}].");
        }

        $this->mappers[$partyType] = $mapper;
    }

    public function all(): array
    {
        return array_values($this->mappers);
    }

    public function find(string $partyType): ?EventPartyMapperContract
    {
        return $this->mappers[$partyType] ?? null;
    }
}

