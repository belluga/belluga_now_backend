<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Str;

class AccountProfileRegistryService
{
    /** @var array<string, array<string, mixed>|null> */
    private array $typeDefinitionCache = [];

    private int $typeDefinitionCacheRevision = -1;

    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly AccountProfileTypeMediaService $mediaService,
        private readonly AccountProfileCapabilityResolverContract $capabilityResolver,
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
                $capabilities = $this->resolveCapabilitiesPayload($type);

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
                    'capability_revision' => max(0, (int) ($type->capability_revision ?? 0)),
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
        $this->refreshTypeDefinitionCacheIfStale();
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
        $capabilities = $this->resolveCapabilitiesPayload($type);

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
            'capability_revision' => max(0, (int) ($type->capability_revision ?? 0)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function capabilityDefinitions(): array
    {
        return array_values($this->capabilityResolver->definitions());
    }

    /** @return array<string, array{value:mixed,parameters:array<string,int>}> */
    public function capabilityCreationConfiguration(): array
    {
        return $this->capabilityResolver->materializeConfigurationForCreation();
    }

    public function locationPolicy(string $profileType): string
    {
        return (string) $this->valueForType($profileType, 'location_policy', 'disabled');
    }

    public function isMapPoiEnabled(string $profileType): bool
    {
        return $this->valueForType($profileType, 'is_map_poi_enabled', false) === true;
    }

    public function isPhysicalHostEnabled(string $profileType): bool
    {
        return $this->valueForType($profileType, 'is_physical_host_enabled', false) === true;
    }

    public function isReferenceLocationEnabled(string $profileType): bool
    {
        return $this->valueForType($profileType, 'is_reference_location_enabled', false) === true;
    }

    public function hasEvents(string $profileType): bool
    {
        return $this->valueForType($profileType, 'has_events', false) === true;
    }

    public function hasGallery(string $profileType): bool
    {
        return $this->valueForType($profileType, 'has_gallery', false) === true;
    }

    public function hasNestedProfileGroups(string $profileType): bool
    {
        return $this->valueForType($profileType, 'has_nested_profile_groups', false) === true;
    }

    public function hasContactChannels(string $profileType): bool
    {
        return $this->valueForType($profileType, 'has_contact_channels', false) === true;
    }

    public function hasExternalLinks(string $profileType): bool
    {
        return $this->valueForType($profileType, 'has_external_links', false) === true;
    }

    public function hasExternalLinksAuthoritatively(string $profileType): bool
    {
        $type = TenantProfileType::query()
            ->where('type', trim($profileType))
            ->first();

        if (! $type instanceof TenantProfileType) {
            return false;
        }

        return $this->capabilityResolver->resolveForProfileType($type, 'has_external_links')['effective']['value'] === true;
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
     * @return array<string, array<string, mixed>>
     */
    private function resolveCapabilitiesPayload(TenantProfileType $type): array
    {
        return $this->capabilityResolver->resolveAllForProfileType($type);
    }

    private function valueForType(string $profileType, string $key, mixed $fallback): mixed
    {
        $typeDefinition = $this->typeDefinition($profileType);
        if ($typeDefinition === null) {
            return $fallback;
        }

        return data_get($typeDefinition, "capabilities.{$key}.effective.value", $fallback);
    }

    private function refreshTypeDefinitionCacheIfStale(): void
    {
        $revision = AccountProfileTypeSetProvider::currentRevision();
        if ($this->typeDefinitionCacheRevision === $revision) {
            return;
        }

        $this->typeDefinitionCache = [];
        $this->typeDefinitionCacheRevision = $revision;
    }
}
