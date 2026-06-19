<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Str;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileRegistryService
{
    /** @var array<string, array<string, mixed>|null> */
    private array $typeDefinitionCache = [];

    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly AccountProfileTypeMediaService $mediaService,
        private readonly AccountProfileTypeCapabilityCatalog $capabilityCatalog,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(?string $baseUrl = null): array
    {
        return TenantProfileType::query()
            ->orderBy('type')
            ->get()
            ->map(function (TenantProfileType $type) use ($baseUrl): array {
                $visual = $this->resolveVisualPayload($type, $baseUrl);
                $labels = $this->resolveLabels($type);
                $capabilities = $this->resolveCapabilitiesPayload(
                    $this->arrayFrom($type->capabilities ?? [])
                );

                return [
                    'type' => $type->type,
                    'label' => $labels['singular'],
                    'labels' => $labels,
                    'allowed_taxonomies' => array_values(array_filter(
                        is_array($type->allowed_taxonomies ?? null)
                            ? $type->allowed_taxonomies
                            : [],
                        static fn ($value): bool => is_string($value) && $value !== ''
                    )),
                    'visual' => $visual,
                    'poi_visual' => $visual,
                    'capabilities' => $capabilities,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function typeDefinition(string $profileType, ?string $baseUrl = null): ?array
    {
        $normalizedType = trim($profileType);
        if ($normalizedType === '') {
            return null;
        }

        $cacheKey = sprintf('%s|%s', $baseUrl ?? '__null__', $normalizedType);
        if (array_key_exists($cacheKey, $this->typeDefinitionCache)) {
            return $this->typeDefinitionCache[$cacheKey];
        }

        $type = TenantProfileType::query()
            ->where('type', $normalizedType)
            ->first();

        if (! $type instanceof TenantProfileType) {
            return $this->typeDefinitionCache[$cacheKey] = null;
        }

        $visual = $this->resolveVisualPayload($type, $baseUrl);
        $labels = $this->resolveLabels($type);
        $capabilities = $this->resolveCapabilitiesPayload(
            $this->arrayFrom($type->capabilities ?? [])
        );

        return $this->typeDefinitionCache[$cacheKey] = [
            'type' => $type->type,
            'label' => $labels['singular'],
            'labels' => $labels,
            'allowed_taxonomies' => array_values(array_filter(
                is_array($type->allowed_taxonomies ?? null)
                    ? $type->allowed_taxonomies
                    : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => $capabilities,
        ];
    }

    public function isPoiEnabled(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return $this->capabilityCatalog->isEnabled(
            AccountProfileTypeCapabilityCatalog::IS_POI_ENABLED,
            is_array($capabilities) ? $capabilities : [],
        );
    }

    public function isReferenceLocationEnabled(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return $this->capabilityCatalog->isEnabled(
            AccountProfileTypeCapabilityCatalog::IS_REFERENCE_LOCATION_ENABLED,
            is_array($capabilities) ? $capabilities : [],
        );
    }

    public function hasEvents(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return $this->capabilityCatalog->isEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_EVENTS,
            is_array($capabilities) ? $capabilities : [],
        );
    }

    public function hasGallery(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return $this->capabilityCatalog->isEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_GALLERY,
            is_array($capabilities) ? $capabilities : [],
        );
    }

    public function hasNestedProfileGroups(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return $this->capabilityCatalog->isEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS,
            is_array($capabilities) ? $capabilities : [],
        );
    }

    /**
     * @return array<string, string>|null
     */
    public function resolvePoiVisual(string $profileType): ?array
    {
        $definition = $this->typeDefinition($profileType);
        $poiVisual = $definition['visual'] ?? $definition['poi_visual'] ?? null;

        return is_array($poiVisual) ? $poiVisual : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveVisualPayload(TenantProfileType $type, ?string $baseUrl = null): ?array
    {
        $visual = $this->poiVisualNormalizer->normalize($type->visual ?? $type->poi_visual ?? null);
        if (! is_array($visual)) {
            return null;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return $visual;
        }

        $rawUrl = is_string($type->type_asset_url ?? null) ? trim((string) $type->type_asset_url) : '';
        if ($rawUrl === '') {
            return $visual;
        }

        $visual['image_url'] = $baseUrl !== null
            ? $this->mediaService->normalizePublicUrl($baseUrl, $type, 'type_asset', $rawUrl)
            : $rawUrl;

        return $visual;
    }

    /**
     * @return array{singular: string, plural: string}
     */
    private function resolveLabels(TenantProfileType $type): array
    {
        $rawLabels = is_array($type->labels ?? null) ? $type->labels : [];
        $singular = trim((string) ($rawLabels['singular'] ?? $type->label ?? ''));
        $plural = trim((string) ($rawLabels['plural'] ?? ''));

        if ($singular === '') {
            $singular = trim((string) ($type->type ?? ''));
        }

        if ($plural === '') {
            $plural = Str::plural($singular);
        }

        return [
            'singular' => $singular,
            'plural' => $plural,
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, bool>
     */
    private function resolveCapabilitiesPayload(array $capabilities): array
    {
        return $this->capabilityCatalog->normalize($capabilities, $capabilities);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
