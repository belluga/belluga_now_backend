<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters;

use App\Application\AccountProfiles\AccountProfilePublicCatalogSnapshotReader;
use App\Integration\DiscoveryFilters\Concerns\FormatsDiscoveryFilterTypeOptions;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterEntityProviderContract;
use Illuminate\Contracts\Container\Container;

final class AccountProfileDiscoveryFilterEntityProvider implements DiscoveryFilterEntityProviderContract
{
    use FormatsDiscoveryFilterTypeOptions;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function entity(): string
    {
        return 'account_profile';
    }

    public function types(): array
    {
        return collect($this->snapshotReader()->catalogSnapshot()->filterOptions())
            ->map(fn (array $type): array => [
                'value' => (string) ($type['value'] ?? ''),
                'label' => (string) ($type['label'] ?? $type['value'] ?? ''),
                ...($this->normalizeVisual($type['poi_visual'] ?? $type['visual'] ?? null) !== null
                    ? ['visual' => $this->normalizeVisual($type['poi_visual'] ?? $type['visual'] ?? null)]
                    : []),
                'allowed_taxonomies' => $this->normalizeStringList($type['allowed_taxonomies'] ?? []),
            ])
            ->filter(static fn (array $item): bool => trim($item['value']) !== '' && trim($item['label']) !== '')
            ->values()
            ->all();
    }

    public function taxonomiesForTypes(array $typeValues): array
    {
        $selected = array_flip($this->normalizeStringList($typeValues));
        $allowed = [];

        foreach ($this->types() as $type) {
            if ($selected !== [] && ! isset($selected[$type['value']])) {
                continue;
            }
            foreach ($type['allowed_taxonomies'] ?? [] as $taxonomy) {
                $allowed[] = (string) $taxonomy;
            }
        }

        return $this->taxonomyOptions($this->normalizeStringList($allowed));
    }

    private function snapshotReader(): AccountProfilePublicCatalogSnapshotReader
    {
        /** @var AccountProfilePublicCatalogSnapshotReader $reader */
        $reader = $this->container->make(AccountProfilePublicCatalogSnapshotReader::class);

        return $reader;
    }
}
