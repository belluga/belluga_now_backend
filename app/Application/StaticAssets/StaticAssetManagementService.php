<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\AccountProfiles\AccountProfileRichTextSanitizer;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Application\Taxonomies\TaxonomyValidationService;
use App\Models\Tenants\StaticAsset;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromStaticAssetJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class StaticAssetManagementService
{
    public function __construct(
        private readonly StaticProfileTypeRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): StaticAsset
    {
        $payload = AccountProfileRichTextSanitizer::sanitizePayload($payload);
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

        $payload['taxonomy_terms'] = $this->resolveTaxonomyTerms(
            is_array($payload['taxonomy_terms'] ?? null) ? $payload['taxonomy_terms'] : [],
            $definition
        );

        try {
            $asset = DB::connection('tenant')->transaction(function () use ($payload): StaticAsset {
                if (! array_key_exists('is_active', $payload)) {
                    $payload['is_active'] = true;
                }
                $payload['location'] = $this->formatLocation($payload['location'] ?? null);

                return StaticAsset::create($payload)->fresh();
            });

            UpsertMapPoiFromStaticAssetJob::dispatch((string) $asset->_id);

            return $asset;
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
     * @param  array<string, mixed>  $attributes
     */
    public function update(StaticAsset $asset, array $attributes): StaticAsset
    {
        $attributes = AccountProfileRichTextSanitizer::sanitizePayload($attributes);
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
            $attributes['taxonomy_terms'] = $this->resolveTaxonomyTerms(
                is_array($attributes['taxonomy_terms'] ?? null) ? $attributes['taxonomy_terms'] : [],
                $definition
            );
        }

        if (array_key_exists('location', $attributes)) {
            $attributes['location'] = $this->formatLocation($attributes['location']);
        }

        try {
            $asset->fill($attributes);
            $asset->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Static asset slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'static_asset' => ['Something went wrong when trying to update the static asset.'],
            ]);
        }

        $asset = $asset->fresh();
        UpsertMapPoiFromStaticAssetJob::dispatch((string) $asset->_id);

        return $asset;
    }

    public function delete(StaticAsset $asset): void
    {
        $asset->delete();
        DeleteMapPoiByRefJob::dispatch('static', (string) $asset->_id);
    }

    public function restore(StaticAsset $asset): StaticAsset
    {
        $asset->restore();

        $asset = $asset->fresh();
        UpsertMapPoiFromStaticAssetJob::dispatch((string) $asset->_id);

        return $asset;
    }

    public function forceDelete(StaticAsset $asset): void
    {
        $asset->forceDelete();
        DeleteMapPoiByRefJob::dispatch('static', (string) $asset->_id);
    }

    /**
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
     * @param  array<int, array<string, mixed>>  $terms
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
     * @param  array<int, array<string, mixed>>  $taxonomyTerms
     * @param  array<string, mixed>  $definition
     * @return array<int, array{type: string, value: string, name: string, taxonomy_name: string, label: string}>
     */
    private function resolveTaxonomyTerms(array $taxonomyTerms, array $definition): array
    {
        if ($taxonomyTerms === []) {
            return [];
        }

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

        return $this->taxonomyTermSummaryResolver->resolve($taxonomyTerms);
    }
}
