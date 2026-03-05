<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventPartyMapperContract
{
    public function partyType(): string;

    public function defaultCanEdit(): bool;

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function mapMetadata(array $source): array;
}

