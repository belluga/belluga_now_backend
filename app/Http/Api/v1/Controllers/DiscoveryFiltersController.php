<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Belluga\DiscoveryFilters\Data\DiscoveryFilterDefinition;
use Belluga\DiscoveryFilters\Registry\DiscoveryFilterEntityRegistry;
use Belluga\DiscoveryFilters\Services\DiscoveryFilterCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class DiscoveryFiltersController extends Controller
{
    public function show(
        string $tenant_domain,
        string $surface,
        DiscoveryFilterCatalogService $catalog,
        DiscoveryFilterEntityRegistry $registry,
    ): JsonResponse {
        $definitions = $catalog->surfaceDefinitions($surface);
        $entities = [];
        foreach ($definitions as $definition) {
            foreach ($definition->entities as $entity) {
                $entities[$entity] = true;
            }
        }
        $typeOptions = $registry->typesForEntities(array_keys($entities));

        return response()->json([
            'surface' => strtolower(trim($surface)),
            'filters' => array_map(
                static fn ($definition): array => $definition->toArray(),
                $definitions
            ),
            'type_options' => $typeOptions,
            'taxonomy_options' => $this->taxonomyOptionsForDefinitions($definitions, $typeOptions),
        ]);
    }

    /**
     * @param  array<int, DiscoveryFilterDefinition>  $definitions
     * @param  array<string, array<int, array<string, mixed>>>  $typeOptions
     * @return array<string, array{key: string, label: string, terms: array<int, array{value: string, label: string}>}>
     */
    private function taxonomyOptionsForDefinitions(array $definitions, array $typeOptions): array
    {
        $taxonomySlugs = $this->allowedTaxonomySlugs($definitions, $typeOptions);
        if ($taxonomySlugs === []) {
            return [];
        }

        $taxonomies = Taxonomy::query()
            ->whereIn('slug', $taxonomySlugs)
            ->orderBy('name')
            ->get();

        if ($taxonomies->isEmpty()) {
            return [];
        }

        $taxonomyIdsBySlug = [];
        foreach ($taxonomies as $taxonomy) {
            $slug = $this->normalizeToken($taxonomy->slug ?? '');
            $taxonomyId = trim((string) ($taxonomy->_id ?? ''));
            if ($slug === '' || $taxonomyId === '') {
                continue;
            }
            $taxonomyIdsBySlug[$slug] = $taxonomyId;
        }

        $termsByTaxonomyId = TaxonomyTerm::query()
            ->whereIn('taxonomy_id', array_values($taxonomyIdsBySlug))
            ->orderBy('name')
            ->get()
            ->groupBy(static fn (TaxonomyTerm $term): string => (string) ($term->taxonomy_id ?? ''));

        $payload = [];
        foreach ($taxonomies as $taxonomy) {
            $slug = $this->normalizeToken($taxonomy->slug ?? '');
            if ($slug === '' || ! isset($taxonomyIdsBySlug[$slug])) {
                continue;
            }
            $taxonomyId = $taxonomyIdsBySlug[$slug];
            $payload[$slug] = [
                'key' => $slug,
                'label' => trim((string) ($taxonomy->name ?? $slug)),
                'terms' => $termsByTaxonomyId
                    ->get($taxonomyId, collect())
                    ->map(static fn (TaxonomyTerm $term): array => [
                        'value' => strtolower(trim((string) ($term->slug ?? ''))),
                        'label' => trim((string) ($term->name ?? $term->slug ?? '')),
                    ])
                    ->filter(static fn (array $term): bool => $term['value'] !== '' && $term['label'] !== '')
                    ->values()
                    ->all(),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<int, DiscoveryFilterDefinition>  $definitions
     * @param  array<string, array<int, array<string, mixed>>>  $typeOptions
     * @return array<int, string>
     */
    private function allowedTaxonomySlugs(array $definitions, array $typeOptions): array
    {
        $slugs = [];

        foreach ($definitions as $definition) {
            foreach (array_keys($definition->taxonomyValuesByGroup) as $taxonomySlug) {
                $this->appendToken($slugs, $taxonomySlug);
            }

            foreach ($definition->entities as $entity) {
                $entityKey = $this->normalizeToken($entity);
                if ($entityKey === '') {
                    continue;
                }

                $selectedTypes = array_flip($definition->typesByEntity[$entityKey] ?? []);
                foreach ($typeOptions[$entityKey] ?? [] as $option) {
                    $typeValue = $this->normalizeToken($option['value'] ?? '');
                    if ($selectedTypes !== [] && ! isset($selectedTypes[$typeValue])) {
                        continue;
                    }
                    foreach ($this->normalizeList($option['allowed_taxonomies'] ?? []) as $taxonomySlug) {
                        $this->appendToken($slugs, $taxonomySlug);
                    }
                }
            }
        }

        return array_values($slugs);
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function appendToken(array &$tokens, mixed $value): void
    {
        $token = $this->normalizeToken($value);
        if ($token !== '') {
            $tokens[$token] = $token;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function normalizeToken(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
