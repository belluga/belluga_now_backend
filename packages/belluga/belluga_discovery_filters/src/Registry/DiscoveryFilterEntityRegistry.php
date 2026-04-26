<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Registry;

use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterEntityProviderContract;
use InvalidArgumentException;

final class DiscoveryFilterEntityRegistry
{
    /**
     * @var array<string, DiscoveryFilterEntityProviderContract>
     */
    private array $providers = [];

    public function register(DiscoveryFilterEntityProviderContract $provider): void
    {
        $entity = strtolower(trim($provider->entity()));
        if ($entity === '') {
            throw new InvalidArgumentException('Discovery filter provider entity cannot be empty.');
        }

        $this->providers[$entity] = $provider;
    }

    public function provider(string $entity): ?DiscoveryFilterEntityProviderContract
    {
        return $this->providers[strtolower(trim($entity))] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function entities(): array
    {
        return array_values(array_keys($this->providers));
    }

    /**
     * @return array<string, array<int, array{value: string, label: string, visual?: array<string, mixed>, allowed_taxonomies?: array<int, string>}>>
     */
    public function typesForEntities(array $entities): array
    {
        $resolved = [];
        foreach ($entities as $entity) {
            $entityKey = strtolower(trim((string) $entity));
            $provider = $this->provider($entityKey);
            if ($provider === null) {
                continue;
            }
            $resolved[$entityKey] = $provider->types();
        }

        return $resolved;
    }
}
