<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventPartyMapperRegistryContract
{
    public function register(EventPartyMapperContract $mapper): void;

    /**
     * @return array<int, EventPartyMapperContract>
     */
    public function all(): array;

    public function find(string $partyType): ?EventPartyMapperContract;
}

