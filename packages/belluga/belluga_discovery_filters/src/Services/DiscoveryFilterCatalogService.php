<?php

declare(strict_types=1);

namespace Belluga\DiscoveryFilters\Services;

use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterSettingsContract;
use Belluga\DiscoveryFilters\Data\DiscoveryFilterDefinition;

final readonly class DiscoveryFilterCatalogService
{
    public function __construct(
        private DiscoveryFilterSettingsContract $settings,
    ) {}

    /**
     * @return array<int, DiscoveryFilterDefinition>
     */
    public function surfaceDefinitions(string $surface): array
    {
        $surfaceKey = $this->normalizeToken($surface);
        if ($surfaceKey === '') {
            return [];
        }

        $settings = $this->settings->resolveDiscoveryFiltersSettings();
        $surfaces = $this->normalizeArray($settings['surfaces'] ?? []);
        $surfaceConfig = $this->normalizeArray($surfaces[$surfaceKey] ?? []);
        $filters = $this->normalizeList($surfaceConfig['filters'] ?? []);

        $definitions = [];
        foreach ($filters as $filter) {
            $payload = $this->normalizeArray($filter);
            if ($payload === []) {
                continue;
            }
            $payload['surface'] ??= $surfaceKey;

            $definition = DiscoveryFilterDefinition::fromArray($payload);
            if ($definition->key === '' || $definition->label === '' || $definition->target === '') {
                continue;
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function surfacePayload(string $surface): array
    {
        return array_map(
            static fn (DiscoveryFilterDefinition $definition): array => $definition->toArray(),
            $this->surfaceDefinitions($surface)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private function normalizeToken(string $value): string
    {
        return strtolower(trim($value));
    }
}
