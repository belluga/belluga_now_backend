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
                    'capabilities' => [
                        'is_favoritable' => (bool) ($type->capabilities['is_favoritable'] ?? false),
                        'is_poi_enabled' => (bool) ($type->capabilities['is_poi_enabled'] ?? false),
                        'has_bio' => (bool) ($type->capabilities['has_bio'] ?? false),
                        'has_content' => (bool) ($type->capabilities['has_content'] ?? false),
                        'has_taxonomies' => (bool) ($type->capabilities['has_taxonomies'] ?? false),
                        'has_avatar' => (bool) ($type->capabilities['has_avatar'] ?? false),
                        'has_cover' => (bool) ($type->capabilities['has_cover'] ?? false),
                        'has_events' => (bool) ($type->capabilities['has_events'] ?? false),
                    ],
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
}
