<?php

declare(strict_types=1);

namespace App\Integration\DiscoveryFilters;

use App\Integration\DiscoveryFilters\Concerns\FormatsDiscoveryFilterTypeOptions;
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterEntityProviderContract;

final class EventDiscoveryFilterEntityProvider implements DiscoveryFilterEntityProviderContract
{
    use FormatsDiscoveryFilterTypeOptions;

    public function entity(): string
    {
        return 'event';
    }

    public function types(): array
    {
        $allowedTaxonomies = $this->eventTaxonomySlugs();

        return EventType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EventType $type): array => [
                'value' => (string) ($type->slug ?? ''),
                'label' => (string) ($type->name ?? $type->slug ?? ''),
                ...($this->normalizeVisual($type->poi_visual ?? $type->visual ?? null) !== null
                    ? ['visual' => $this->normalizeVisual($type->poi_visual ?? $type->visual ?? null)]
                    : []),
                'allowed_taxonomies' => $allowedTaxonomies,
            ])
            ->filter(static fn (array $item): bool => trim($item['value']) !== '' && trim($item['label']) !== '')
            ->values()
            ->all();
    }

    public function taxonomiesForTypes(array $typeValues): array
    {
        return $this->taxonomyOptions($this->eventTaxonomySlugs());
    }

    /**
     * @return array<int, string>
     */
    private function eventTaxonomySlugs(): array
    {
        return Taxonomy::query()
            ->where('applies_to', 'event')
            ->pluck('slug')
            ->map(static fn ($slug): string => strtolower(trim((string) $slug)))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->values()
            ->all();
    }
}
