<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\StaticProfileType;

class StaticProfileTypeRegistryService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return StaticProfileType::query()
            ->orderBy('type')
            ->get()
            ->map(function (StaticProfileType $type): array {
                $typeKey = trim((string) ($type->type ?? ''));
                $mapCategory = trim((string) ($type->map_category ?? ''));

                return [
                    'type' => $typeKey,
                    'label' => $type->label,
                    'map_category' => $mapCategory !== '' ? $mapCategory : $typeKey,
                    'allowed_taxonomies' => array_values(array_filter(
                        is_array($type->allowed_taxonomies ?? null)
                            ? $type->allowed_taxonomies
                            : [],
                        static fn ($value): bool => is_string($value) && $value !== ''
                    )),
                    'poi_visual' => $this->poiVisualNormalizer->normalize($type->poi_visual ?? null),
                    'capabilities' => [
                        'is_poi_enabled' => (bool) ($type->capabilities['is_poi_enabled'] ?? false),
                        'has_bio' => (bool) ($type->capabilities['has_bio'] ?? false),
                        'has_taxonomies' => (bool) ($type->capabilities['has_taxonomies'] ?? false),
                        'has_avatar' => (bool) ($type->capabilities['has_avatar'] ?? false),
                        'has_cover' => (bool) ($type->capabilities['has_cover'] ?? false),
                        'has_content' => (bool) ($type->capabilities['has_content'] ?? false),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function typeDefinition(string $profileType): ?array
    {
        foreach ($this->registry() as $entry) {
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

    public function resolveMapCategory(string $profileType): string
    {
        $definition = $this->typeDefinition($profileType);
        $mapCategory = trim((string) ($definition['map_category'] ?? ''));
        if ($mapCategory !== '') {
            return $mapCategory;
        }

        $fallback = trim($profileType);

        return $fallback !== '' ? $fallback : 'static';
    }

    /**
     * @return array<string, string>|null
     */
    public function resolvePoiVisual(string $profileType): ?array
    {
        $definition = $this->typeDefinition($profileType);
        $poiVisual = $definition['poi_visual'] ?? null;

        return is_array($poiVisual) ? $poiVisual : null;
    }
}
