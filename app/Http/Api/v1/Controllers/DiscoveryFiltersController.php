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
        $surfaceKey = strtolower(trim($surface));
        [$definitions, $typeOptions] = $this->definitionsForSurface(
            surface: $surfaceKey,
            catalog: $catalog,
            registry: $registry,
        );

        return response()->json([
            'surface' => $surfaceKey,
            'filters' => array_map(
                static fn ($definition): array => $definition->toArray(),
                $definitions
            ),
            'type_options' => $typeOptions,
            'taxonomy_options' => $this->taxonomyOptionsForDefinitions($surfaceKey, $definitions, $typeOptions),
        ]);
    }

    /**
     * @return array{0: array<int, DiscoveryFilterDefinition>, 1: array<string, array<int, array<string, mixed>>>}
     */
    private function definitionsForSurface(
        string $surface,
        DiscoveryFilterCatalogService $catalog,
        DiscoveryFilterEntityRegistry $registry,
    ): array {
        if ($surface === 'home.events') {
            $typeOptions = $registry->typesForEntities(['event']);

            return [
                $this->typeDrivenDefinitions(
                    surface: $surface,
                    target: 'event_occurrence',
                    entity: 'event',
                    typeOptions: $typeOptions['event'] ?? [],
                ),
                $typeOptions,
            ];
        }

        if ($surface === 'discovery.account_profiles') {
            $typeOptions = $registry->typesForEntities(['account_profile']);

            return [
                $this->typeDrivenDefinitions(
                    surface: $surface,
                    target: 'account_profile',
                    entity: 'account_profile',
                    typeOptions: $typeOptions['account_profile'] ?? [],
                ),
                $typeOptions,
            ];
        }

        $definitions = $catalog->surfaceDefinitions($surface);
        $entities = [];
        foreach ($definitions as $definition) {
            foreach ($definition->entities as $entity) {
                $entities[$entity] = true;
            }
        }

        return [$definitions, $registry->typesForEntities(array_keys($entities))];
    }

    /**
     * @param  array<int, array<string, mixed>>  $typeOptions
     * @return array<int, DiscoveryFilterDefinition>
     */
    private function typeDrivenDefinitions(
        string $surface,
        string $target,
        string $entity,
        array $typeOptions,
    ): array {
        $definitions = [];
        foreach ($typeOptions as $option) {
            $typeValue = $this->normalizeToken($option['value'] ?? '');
            $label = trim((string) ($option['label'] ?? $typeValue));
            if ($typeValue === '' || $label === '') {
                continue;
            }

            $visual = $this->normalizeVisual($option['visual'] ?? null);
            $definitions[] = DiscoveryFilterDefinition::fromArray([
                'key' => $typeValue,
                'surface' => $surface,
                'target' => $target,
                'label' => $label,
                'primary_selection_mode' => 'single',
                ...($visual['icon'] !== null ? ['icon' => $visual['icon']] : []),
                ...($visual['color'] !== null ? ['color' => $visual['color']] : []),
                'query' => [
                    'entities' => [$entity],
                    'types_by_entity' => [
                        $entity => [$typeValue],
                    ],
                    'taxonomy' => [],
                ],
            ]);
        }

        return $definitions;
    }

    /**
     * @return array{icon: string|null, color: string|null}
     */
    private function normalizeVisual(mixed $value): array
    {
        if (! is_array($value)) {
            return ['icon' => null, 'color' => null];
        }

        $mode = $this->normalizeToken($value['mode'] ?? 'icon');
        if ($mode !== '' && $mode !== 'icon') {
            return ['icon' => null, 'color' => $this->normalizeColor($value['color'] ?? null)];
        }

        $icon = trim((string) ($value['icon'] ?? ''));

        return [
            'icon' => $icon === '' ? null : $icon,
            'color' => $this->normalizeColor($value['color'] ?? null),
        ];
    }

    private function normalizeColor(mixed $value): ?string
    {
        $color = strtoupper(trim((string) $value));
        if (! preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return null;
        }

        return $color;
    }

    /**
     * @param  array<int, DiscoveryFilterDefinition>  $definitions
     * @param  array<string, array<int, array<string, mixed>>>  $typeOptions
     * @return array<string, array{key: string, label: string, terms: array<int, array{value: string, label: string}>}>
     */
    private function taxonomyOptionsForDefinitions(string $surface, array $definitions, array $typeOptions): array
    {
        $taxonomySlugs = $this->allowedTaxonomySlugs($definitions, $typeOptions);
        foreach ($this->surfaceContextTaxonomySlugs($surface) as $taxonomySlug) {
            $this->appendToken($taxonomySlugs, $taxonomySlug);
        }
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
     * @return array<int, string>
     */
    private function surfaceContextTaxonomySlugs(string $surface): array
    {
        $entity = match ($surface) {
            'home.events' => 'event',
            'discovery.account_profiles' => 'account_profile',
            default => null,
        };
        if ($entity === null) {
            return [];
        }

        $slugs = [];
        foreach (Taxonomy::query()->get(['slug', 'applies_to']) as $taxonomy) {
            $slug = $this->normalizeToken($taxonomy->slug ?? '');
            if ($slug === '' || ! $this->taxonomyAppliesTo($taxonomy->applies_to ?? null, $entity)) {
                continue;
            }
            $this->appendToken($slugs, $slug);
        }

        return array_values($slugs);
    }

    private function taxonomyAppliesTo(mixed $value, string $entity): bool
    {
        foreach ($this->normalizeList($value) as $candidate) {
            if ($this->normalizeToken($candidate) === $entity) {
                return true;
            }
        }

        return false;
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
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values($value) : [];
    }

    private function normalizeToken(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
