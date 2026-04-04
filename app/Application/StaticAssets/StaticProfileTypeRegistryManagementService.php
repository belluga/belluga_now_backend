<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Shared\MapPois\MapPoiProjectionRefService;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromStaticAssetJob;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class StaticProfileTypeRegistryManagementService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly MapPoiProjectionRefService $mapPoiProjectionRefs,
        private readonly StaticProfileTypeMediaService $mediaService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Request $request, array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (StaticProfileType::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages([
                'type' => ['Static profile type already exists.'],
            ]);
        }

        $entry = $this->buildEntry($payload, $type);
        $this->ensureTypeAssetRequirements(
            $entry['visual'] ?? null,
            $request,
            null,
            false,
        );
        $model = StaticProfileType::create($entry);
        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;

        return $this->toPayload($model, $request->getSchemeAndHttpHost());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(Request $request, string $type, array $payload): array
    {
        $type = trim($type);
        $model = StaticProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Static profile type not found.');
        }

        $nextType = array_key_exists('type', $payload)
            ? trim((string) $payload['type'])
            : (string) ($model->type ?? '');
        $currentType = (string) ($model->type ?? '');
        $currentMapCategory = $this->normalizeMapCategory(
            $model->map_category,
            $currentType
        );
        if ($nextType !== $currentType) {
            if (StaticProfileType::query()->where('type', $nextType)->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['Static profile type already exists.'],
                ]);
            }
        }

        $entry = $this->mergeEntry(
            $model,
            $payload,
            $nextType,
            $currentType,
        );
        $currentCapabilities = is_array($model->capabilities ?? null)
            ? $model->capabilities
            : [];
        $currentPoiEnabled = (bool) ($currentCapabilities['is_poi_enabled'] ?? false);
        $nextCapabilities = is_array($entry['capabilities'] ?? null)
            ? $entry['capabilities']
            : [];
        $nextPoiEnabled = (bool) ($nextCapabilities['is_poi_enabled'] ?? false);
        $currentPoiVisual = $this->poiVisualNormalizer->normalize($model->visual ?? $model->poi_visual ?? null);
        $nextPoiVisual = $this->poiVisualNormalizer->normalize($entry['visual'] ?? $entry['poi_visual'] ?? null);
        $poiVisualChanged = $currentPoiVisual !== $nextPoiVisual;
        $currentTypeAssetUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);

        $this->ensureTypeAssetRequirements(
            $nextPoiVisual,
            $request,
            $currentTypeAssetUrl,
            $request->boolean('remove_type_asset'),
        );

        try {
            $model->fill($entry);
            $model->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'type' => ['Static profile type already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'profile_type' => ['Something went wrong when trying to update the static profile type.'],
            ]);
        }

        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;
        $nextTypeAssetUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);
        $typeAssetChanged = $currentTypeAssetUrl !== $nextTypeAssetUrl;
        $nextMapCategory = (string) ($entry['map_category'] ?? $nextType);
        $forcedCheckpoint = $this->toCheckpoint($model->updated_at ?? null);
        $shouldRefreshMapProjection = $nextType !== $currentType
            || $nextMapCategory !== $currentMapCategory
            || $currentPoiEnabled !== $nextPoiEnabled
            || $poiVisualChanged
            || $typeAssetChanged;

        if ($shouldRefreshMapProjection) {
            $queryType = $nextType === $currentType ? $nextType : $currentType;
            $assetIds = StaticAsset::query()
                ->where('profile_type', $queryType)
                ->get(['_id'])
                ->map(static fn (StaticAsset $asset): string => (string) $asset->getKey())
                ->all();

            if ($nextType !== $currentType && $assetIds !== []) {
                StaticAsset::query()
                    ->where('profile_type', $currentType)
                    ->update(['profile_type' => $nextType]);
            }

            if ($assetIds !== []) {
                if (! $nextPoiEnabled) {
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $assetIds,
                        static function (string $assetId): void {
                            DeleteMapPoiByRefJob::dispatch('static', $assetId);
                        },
                    );
                } else {
                    $checkpoint = $forcedCheckpoint > 0 ? $forcedCheckpoint : null;
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $assetIds,
                        static function (string $assetId) use ($checkpoint): void {
                            UpsertMapPoiFromStaticAssetJob::dispatch($assetId, $checkpoint);
                        },
                    );
                }
            }
        }

        return $this->toPayload($model, $request->getSchemeAndHttpHost());
    }

    public function previewDisableProjectionCount(string $type): int
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return 0;
        }

        $assetIds = StaticAsset::query()
            ->where('profile_type', $normalizedType)
            ->get(['_id'])
            ->map(static fn (StaticAsset $asset): string => (string) $asset->getKey())
            ->all();

        return $this->mapPoiProjectionRefs->countByRefType('static', $assetIds);
    }

    public function delete(string $type): void
    {
        $type = trim($type);
        $model = StaticProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Static profile type not found.');
        }

        $model->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload, string $type): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $visual = $this->resolveIncomingVisual($payload);

        return [
            'type' => $type,
            'label' => trim((string) ($payload['label'] ?? '')),
            'map_category' => $this->normalizeMapCategory($payload['map_category'] ?? null, $type),
            'allowed_taxonomies' => $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => [
                'is_poi_enabled' => (bool) ($capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($capabilities['has_bio'] ?? false),
                'has_taxonomies' => (bool) ($capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($capabilities['has_cover'] ?? false),
                'has_content' => (bool) ($capabilities['has_content'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(
        StaticProfileType $existing,
        array $payload,
        string $resolvedType,
        string $previousType,
    ): array {
        $capabilities = $payload['capabilities'] ?? [];
        $currentCapabilities = $existing->capabilities ?? [];
        $currentMapCategory = trim((string) ($existing->map_category ?? ''));
        $resolvedMapCategory = array_key_exists('map_category', $payload)
            ? $this->normalizeMapCategory($payload['map_category'] ?? null, $resolvedType)
            : (
                $currentMapCategory === '' || $currentMapCategory === $previousType
                    ? $this->normalizeMapCategory(null, $resolvedType)
                    : $currentMapCategory
            );
        $visual = $this->resolveIncomingVisual($payload, $existing->visual ?? $existing->poi_visual ?? null);

        return [
            'type' => $resolvedType,
            'label' => array_key_exists('label', $payload)
                ? trim((string) $payload['label'])
                : (string) ($existing->label ?? ''),
            'map_category' => $resolvedMapCategory,
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? [])
                : $this->normalizeTaxonomies($existing->allowed_taxonomies ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => [
                'is_poi_enabled' => array_key_exists('is_poi_enabled', $capabilities)
                    ? (bool) $capabilities['is_poi_enabled']
                    : (bool) ($currentCapabilities['is_poi_enabled'] ?? false),
                'has_bio' => array_key_exists('has_bio', $capabilities)
                    ? (bool) $capabilities['has_bio']
                    : (bool) ($currentCapabilities['has_bio'] ?? false),
                'has_taxonomies' => array_key_exists('has_taxonomies', $capabilities)
                    ? (bool) $capabilities['has_taxonomies']
                    : (bool) ($currentCapabilities['has_taxonomies'] ?? false),
                'has_avatar' => array_key_exists('has_avatar', $capabilities)
                    ? (bool) $capabilities['has_avatar']
                    : (bool) ($currentCapabilities['has_avatar'] ?? false),
                'has_cover' => array_key_exists('has_cover', $capabilities)
                    ? (bool) $capabilities['has_cover']
                    : (bool) ($currentCapabilities['has_cover'] ?? false),
                'has_content' => array_key_exists('has_content', $capabilities)
                    ? (bool) $capabilities['has_content']
                    : (bool) ($currentCapabilities['has_content'] ?? false),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTaxonomies(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = array_map(static fn ($value): string => trim((string) $value), $raw);

        return array_values(array_filter(array_unique($normalized), static fn (string $value): bool => $value !== ''));
    }

    private function normalizeMapCategory(mixed $raw, string $type): string
    {
        $candidate = trim((string) $raw);
        if ($candidate !== '') {
            return $candidate;
        }

        return trim($type);
    }

    private function toCheckpoint(mixed $value): int
    {
        if ($value instanceof Carbon) {
            return (int) $value->valueOf();
        }

        if ($value instanceof \DateTimeInterface) {
            return (int) Carbon::instance($value)->valueOf();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (int) Carbon::parse($value)->valueOf();
            } catch (\Exception) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(StaticProfileType $model, ?string $baseUrl = null): array
    {
        $visual = $this->resolvePayloadVisual($model, $baseUrl);

        return [
            'type' => (string) $model->type,
            'label' => (string) $model->label,
            'map_category' => $this->normalizeMapCategory($model->map_category ?? null, (string) $model->type),
            'allowed_taxonomies' => array_values(array_filter(
                is_array($model->allowed_taxonomies ?? null)
                    ? $model->allowed_taxonomies
                    : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'visual' => $visual,
            'poi_visual' => $visual,
            'capabilities' => [
                'is_poi_enabled' => (bool) ($model->capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($model->capabilities['has_bio'] ?? false),
                'has_taxonomies' => (bool) ($model->capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($model->capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($model->capabilities['has_cover'] ?? false),
                'has_content' => (bool) ($model->capabilities['has_content'] ?? false),
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveIncomingVisual(array $payload, mixed $fallback = null): ?array
    {
        if (array_key_exists('visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['visual'] ?? null);
        }

        if (array_key_exists('poi_visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['poi_visual'] ?? null);
        }

        return $this->poiVisualNormalizer->normalize($fallback);
    }

    /**
     * @return array<string, string>|null
     */
    private function resolvePayloadVisual(StaticProfileType $model, ?string $baseUrl = null): ?array
    {
        $visual = $this->poiVisualNormalizer->normalize($model->visual ?? $model->poi_visual ?? null);
        if (! is_array($visual)) {
            return null;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return $visual;
        }

        $rawUrl = $this->normalizeTypeAssetUrl($model->type_asset_url ?? null);
        if ($rawUrl === null) {
            return $visual;
        }

        $visual['image_url'] = $baseUrl !== null
            ? $this->mediaService->normalizePublicUrl($baseUrl, $model, 'type_asset', $rawUrl)
            : $rawUrl;

        return $visual;
    }

    private function ensureTypeAssetRequirements(
        ?array $visual,
        Request $request,
        ?string $existingTypeAssetUrl,
        bool $removeTypeAsset,
    ): void {
        if (! is_array($visual)) {
            return;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return;
        }

        $hasUpload = $request->hasFile('type_asset');
        $hasExisting = $existingTypeAssetUrl !== null && ! $removeTypeAsset;
        if ($hasUpload || $hasExisting) {
            return;
        }

        throw ValidationException::withMessages([
            'type_asset' => ['Type asset image is required when image_source is type_asset.'],
        ]);
    }

    private function normalizeTypeAssetUrl(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
