<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Str;

class AccountProfileRegistryService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly AccountProfileTypeMediaService $mediaService,
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
                    is_array($type->capabilities ?? null) ? $type->capabilities : []
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
        foreach ($this->registry($baseUrl) as $entry) {
            if (($entry['type'] ?? null) === $profileType) {
                return $entry;
            }
        }

        return null;
    }

    public function isPoiEnabled(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return (bool) ($capabilities['is_poi_enabled'] ?? false);
    }

    public function isReferenceLocationEnabled(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return (bool) ($capabilities['is_reference_location_enabled'] ?? false);
    }

    public function hasEvents(string $profileType): bool
    {
        $definition = $this->typeDefinition($profileType);
        $capabilities = $definition['capabilities'] ?? [];

        return (bool) ($capabilities['has_events'] ?? false);
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
        $isPoiEnabled = (bool) ($capabilities['is_poi_enabled'] ?? false);
        $isReferenceLocationRequested = (bool) ($capabilities['is_reference_location_enabled'] ?? false);

        return [
            'is_favoritable' => (bool) ($capabilities['is_favoritable'] ?? false),
            'is_inviteable' => (bool) ($capabilities['is_inviteable'] ?? false),
            'is_publicly_discoverable' => (bool) ($capabilities['is_publicly_discoverable'] ?? false),
            'is_poi_enabled' => $isPoiEnabled,
            'is_reference_location_enabled' => $isPoiEnabled && $isReferenceLocationRequested,
            'has_bio' => (bool) ($capabilities['has_bio'] ?? false),
            'has_content' => (bool) ($capabilities['has_content'] ?? false),
            'has_taxonomies' => (bool) ($capabilities['has_taxonomies'] ?? false),
            'has_avatar' => (bool) ($capabilities['has_avatar'] ?? false),
            'has_cover' => (bool) ($capabilities['has_cover'] ?? false),
            'has_events' => (bool) ($capabilities['has_events'] ?? false),
        ];
    }
}
