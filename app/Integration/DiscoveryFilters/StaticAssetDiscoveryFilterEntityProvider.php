<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters;

use App\Integration\DiscoveryFilters\Concerns\FormatsDiscoveryFilterTypeOptions;
use App\Models\Tenants\StaticProfileType;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterEntityProviderContract;

final class StaticAssetDiscoveryFilterEntityProvider implements DiscoveryFilterEntityProviderContract
{
    use FormatsDiscoveryFilterTypeOptions;

    public function entity(): string
    {
        return 'static_asset';
    }

    public function types(): array
    {
        return StaticProfileType::query()
            ->orderBy('label')
            ->get()
            ->map(fn (StaticProfileType $type): array => [
                'value' => (string) ($type->type ?? ''),
                'label' => (string) ($type->label ?? $type->type ?? ''),
                ...($this->normalizeVisual($type->poi_visual ?? $type->visual ?? null) !== null
                    ? ['visual' => $this->normalizeVisual($type->poi_visual ?? $type->visual ?? null)]
                    : []),
                'allowed_taxonomies' => $this->normalizeStringList($type->allowed_taxonomies ?? []),
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
}
