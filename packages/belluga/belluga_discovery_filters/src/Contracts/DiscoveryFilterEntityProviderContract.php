<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Contracts;

interface DiscoveryFilterEntityProviderContract
{
    public function entity(): string;

    /**
     * @return array<int, array{value: string, label: string, visual?: array<string, mixed>, allowed_taxonomies?: array<int, string>}>
     */
    public function types(): array;

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public function taxonomiesForTypes(array $typeValues): array;
}
