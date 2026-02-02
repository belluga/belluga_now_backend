<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Taxonomies\TaxonomyValidationService;
use App\Models\Tenants\StaticAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class StaticAssetManagementService
{
    public function __construct(
        private readonly StaticProfileTypeRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): StaticAsset
    {
        $profileType = (string) $payload['profile_type'];

        $definition = $this->registryService->typeDefinition($profileType);
        if (! $definition) {
            throw ValidationException::withMessages([
                'profile_type' => ['Static profile type is not supported for this tenant.'],
            ]);
        }

        if ($this->registryService->isPoiEnabled($profileType)) {
            $location = $payload['location'] ?? null;
            if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                throw ValidationException::withMessages([
                    'location' => ['Location is required for POI-enabled static profiles.'],
                ]);
            }
        }

        $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
        if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
            $this->taxonomyValidationService->assertTermsAllowedForStaticAsset($taxonomyTerms);
            $allowedTaxonomies = $definition['allowed_taxonomies'] ?? [];
            $allowedTaxonomies = is_array($allowedTaxonomies) ? $allowedTaxonomies : [];

            $types = $this->extractTypes($taxonomyTerms);
            $invalid = array_diff($types, $allowedTaxonomies);
            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    'taxonomy_terms' => ['Some taxonomy types are not allowed for this static profile type.'],
                ]);
            }
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($payload): StaticAsset {
                if (! array_key_exists('is_active', $payload)) {
                    $payload['is_active'] = true;
                }
                $payload['location'] = $this->formatLocation($payload['location'] ?? null);

                return StaticAsset::create($payload)->fresh();
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Static asset slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'static_asset' => ['Something went wrong when trying to create the static asset.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(StaticAsset $asset, array $attributes): StaticAsset
    {
        $profileType = $asset->profile_type;
        if (array_key_exists('profile_type', $attributes)) {
            $profileType = (string) $attributes['profile_type'];
        }

        $definition = $profileType ? $this->registryService->typeDefinition($profileType) : null;
        if (! $definition) {
            throw ValidationException::withMessages([
                'profile_type' => ['Static profile type is not supported for this tenant.'],
            ]);
        }

        if ($profileType && $this->registryService->isPoiEnabled($profileType)) {
            if (array_key_exists('location', $attributes)) {
                $location = $attributes['location'] ?? null;
                if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                    throw ValidationException::withMessages([
                        'location' => ['Location is required for POI-enabled static profiles.'],
                    ]);
                }
            }
        }

        if (array_key_exists('taxonomy_terms', $attributes)) {
            $taxonomyTerms = $attributes['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForStaticAsset($taxonomyTerms);
                $allowedTaxonomies = $definition['allowed_taxonomies'] ?? [];
                $allowedTaxonomies = is_array($allowedTaxonomies) ? $allowedTaxonomies : [];

                $types = $this->extractTypes($taxonomyTerms);
                $invalid = array_diff($types, $allowedTaxonomies);
                if ($invalid !== []) {
                    throw ValidationException::withMessages([
                        'taxonomy_terms' => ['Some taxonomy types are not allowed for this static profile type.'],
                    ]);
                }
            }
        }

        if (array_key_exists('location', $attributes)) {
            $attributes['location'] = $this->formatLocation($attributes['location']);
        }

        $asset->fill($attributes);
        $asset->save();

        return $asset->fresh();
    }

    public function delete(StaticAsset $asset): void
    {
        $asset->delete();
    }

    public function restore(StaticAsset $asset): StaticAsset
    {
        $asset->restore();

        return $asset->fresh();
    }

    public function forceDelete(StaticAsset $asset): void
    {
        $asset->forceDelete();
    }

    /**
     * @param mixed $location
     * @return array<string, mixed>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [(float) $lng, (float) $lat],
        ];
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
}
