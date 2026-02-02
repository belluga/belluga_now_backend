<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Illuminate\Validation\ValidationException;

class TaxonomyValidationService
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     */
    public function assertTermsAllowedForAccountProfile(string $profileType, array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $definition = $this->registryService->typeDefinition($profileType);
        if (! $definition) {
            throw ValidationException::withMessages([
                'profile_type' => ['Profile type is not supported for this tenant.'],
            ]);
        }

        $allowedTaxonomies = $definition['allowed_taxonomies'] ?? [];
        $allowedTaxonomies = is_array($allowedTaxonomies) ? $allowedTaxonomies : [];

        $this->assertTermsValid($terms, 'account_profile');

        $types = $this->extractTypes($terms);
        $invalid = array_diff($types, $allowedTaxonomies);
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'taxonomy_terms' => ['Some taxonomy types are not allowed for this profile type.'],
            ]);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     */
    public function assertTermsAllowedForEvent(array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $this->assertTermsValid($terms, 'event');
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     */
    public function assertTermsAllowedForStaticAsset(array $terms): void
    {
        if ($terms === []) {
            return;
        }

        $this->assertTermsValid($terms, 'static_asset');
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     */
    private function assertTermsValid(array $terms, string $appliesTo): void
    {
        $types = $this->extractTypes($terms);
        $taxonomyMap = $this->loadTaxonomies($types);

        $missingTaxonomies = array_diff($types, array_keys($taxonomyMap));
        if ($missingTaxonomies !== []) {
            throw ValidationException::withMessages([
                'taxonomy_terms' => ['Some taxonomy types are not registered for this tenant.'],
            ]);
        }

        foreach ($taxonomyMap as $slug => $taxonomy) {
            $appliesToList = $taxonomy->applies_to ?? [];
            $appliesToList = is_array($appliesToList) ? $appliesToList : [];
            if (! in_array($appliesTo, $appliesToList, true)) {
                throw ValidationException::withMessages([
                    'taxonomy_terms' => ['Some taxonomy types are not allowed for this object type.'],
                ]);
            }

            $termValues = $this->extractValuesForType($terms, $slug);
            if ($termValues === []) {
                continue;
            }

            $existing = TaxonomyTerm::query()
                ->where('taxonomy_id', (string) $taxonomy->_id)
                ->whereIn('slug', $termValues)
                ->pluck('slug')
                ->map(fn ($value) => (string) $value)
                ->all();

            $missing = array_diff($termValues, $existing);
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'taxonomy_terms' => ['Some taxonomy terms are not registered for this taxonomy.'],
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     * @return array<int, string>
     */
    private function extractTypes(array $terms): array
    {
        $types = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $type = trim((string) ($term['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    /**
     * @param array<int, array<string, mixed>> $terms
     * @return array<int, string>
     */
    private function extractValuesForType(array $terms, string $type): array
    {
        $values = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $termType = trim((string) ($term['type'] ?? ''));
            if ($termType !== $type) {
                continue;
            }
            $value = trim((string) ($term['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $slugs
     * @return array<string, Taxonomy>
     */
    private function loadTaxonomies(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Taxonomy::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy(fn (Taxonomy $taxonomy): string => (string) $taxonomy->slug)
            ->all();
    }
}
