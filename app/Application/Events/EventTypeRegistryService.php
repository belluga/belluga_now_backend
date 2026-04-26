<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\EventType;

class EventTypeRegistryService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly EventTypeMediaService $mediaService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(?string $baseUrl = null): array
    {
        return EventType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EventType $type): array => $this->toPayload($type, $baseUrl))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id, ?string $baseUrl = null): ?array
    {
        $normalizedId = trim($id);
        if ($normalizedId === '') {
            return null;
        }

        $model = EventType::query()->where('_id', $normalizedId)->first();

        return $model ? $this->toPayload($model, $baseUrl) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, ?string $baseUrl = null): ?array
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $model = EventType::query()->where('slug', $normalizedSlug)->first();

        return $model ? $this->toPayload($model, $baseUrl) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(EventType $model, ?string $baseUrl = null): array
    {
        $visual = $this->resolvePayloadVisual($model, $baseUrl);
        $legacy = $this->legacyFieldsFromVisual(
            $visual,
            [
                'icon' => $this->normalizeNullableString($model->icon ?? null),
                'color' => $this->normalizeNullableString($model->color ?? null),
                'icon_color' => $this->normalizeNullableString($model->icon_color ?? null),
            ],
        );

        return [
            'id' => (string) $model->_id,
            'name' => trim((string) ($model->name ?? '')),
            'slug' => trim((string) ($model->slug ?? '')),
            'description' => $this->normalizeNullableString($model->description ?? null),
            'allowed_taxonomies' => $this->normalizeStringList($model->allowed_taxonomies ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'type_asset_url' => $this->normalizeNullableString($model->type_asset_url ?? null),
            'icon' => $legacy['icon'],
            'color' => $legacy['color'],
            'icon_color' => $legacy['icon_color'],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolvePayloadVisual(EventType $model, ?string $baseUrl = null): ?array
    {
        $visual = $this->resolveStoredVisual($model);
        if (! is_array($visual)) {
            return null;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return $visual;
        }

        $rawUrl = $this->normalizeNullableString($model->type_asset_url ?? null);
        if ($rawUrl === null) {
            return $visual;
        }

        $visual['image_url'] = $baseUrl !== null
            ? $this->mediaService->normalizePublicUrl($baseUrl, $model, 'type_asset', $rawUrl)
            : $rawUrl;

        return $visual;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveStoredVisual(EventType $model): ?array
    {
        $visual = $this->poiVisualNormalizer->normalize($model->visual ?? $model->poi_visual ?? null);
        if (is_array($visual)) {
            return $visual;
        }

        return $this->poiVisualNormalizer->normalize([
            'mode' => 'icon',
            'icon' => $model->icon,
            'color' => $model->color,
            'icon_color' => $model->icon_color,
        ]);
    }

    /**
     * @param  array<string, string>|null  $visual
     * @param  array{icon: ?string, color: ?string, icon_color: ?string}  $fallback
     * @return array{icon: ?string, color: ?string, icon_color: ?string}
     */
    private function legacyFieldsFromVisual(?array $visual, array $fallback): array
    {
        if (! is_array($visual)) {
            return $fallback;
        }

        if (($visual['mode'] ?? null) !== 'icon') {
            return [
                'icon' => null,
                'color' => null,
                'icon_color' => null,
            ];
        }

        return [
            'icon' => $this->normalizeNullableString($visual['icon'] ?? null),
            'color' => $this->normalizeNullableString($visual['color'] ?? null),
            'icon_color' => $this->normalizeNullableString($visual['icon_color'] ?? null),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(fn ($item): string => strtolower(trim((string) $item)))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
