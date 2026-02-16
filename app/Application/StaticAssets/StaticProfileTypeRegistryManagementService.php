<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class StaticProfileTypeRegistryManagementService
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (StaticProfileType::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages([
                'type' => ['Static profile type already exists.'],
            ]);
        }

        $entry = $this->buildEntry($payload, $type);
        $model = StaticProfileType::create($entry);

        return $this->toPayload($model);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $type, array $payload): array
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

        $nextMapCategory = (string) ($entry['map_category'] ?? $nextType);
        $shouldSyncMapPoiCategory = $nextMapCategory !== $currentMapCategory;
        $shouldSyncStaticAssets = $nextType !== $currentType;

        if ($shouldSyncStaticAssets || $shouldSyncMapPoiCategory) {
            $assetIds = StaticAsset::query()
                ->where('profile_type', $currentType)
                ->get(['_id'])
                ->map(static fn (StaticAsset $asset): string => (string) $asset->getKey())
                ->all();

            if ($assetIds !== []) {
                if ($shouldSyncStaticAssets) {
                    StaticAsset::query()
                        ->where('profile_type', $currentType)
                        ->update(['profile_type' => $nextType]);
                }

                if ($shouldSyncMapPoiCategory) {
                    [$stringRefIds, $objectRefIds] = $this->splitMapPoiRefIds($assetIds);

                    if ($stringRefIds !== []) {
                        MapPoi::query()
                            ->where('ref_type', 'static')
                            ->whereIn('ref_id', $stringRefIds)
                            ->update(['category' => $nextMapCategory]);
                    }

                    if ($objectRefIds !== []) {
                        MapPoi::query()
                            ->where('ref_type', 'static')
                            ->whereIn('ref_id', $objectRefIds)
                            ->update(['category' => $nextMapCategory]);
                    }
                }
            }
        }

        return $this->toPayload($model);
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload, string $type): array
    {
        $capabilities = $payload['capabilities'] ?? [];

        return [
            'type' => $type,
            'label' => trim((string) ($payload['label'] ?? '')),
            'map_category' => $this->normalizeMapCategory($payload['map_category'] ?? null, $type),
            'allowed_taxonomies' => $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? []),
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
     * @param StaticProfileType $existing
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(
        StaticProfileType $existing,
        array $payload,
        string $resolvedType,
        string $previousType,
    ): array
    {
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

        return [
            'type' => $resolvedType,
            'label' => array_key_exists('label', $payload)
                ? trim((string) $payload['label'])
                : (string) ($existing->label ?? ''),
            'map_category' => $resolvedMapCategory,
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? [])
                : $this->normalizeTaxonomies($existing->allowed_taxonomies ?? []),
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
     * @param mixed $raw
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

    /**
     * @param array<int, string> $rawIds
     * @return array{0: array<int, string>, 1: array<int, ObjectId>}
     */
    private function splitMapPoiRefIds(array $rawIds): array
    {
        $stringIds = [];
        $objectIds = [];

        foreach ($rawIds as $rawId) {
            $id = trim((string) $rawId);
            if ($id === '') {
                continue;
            }

            $stringIds[] = $id;

            if (preg_match('/^[a-f0-9]{24}$/i', $id) === 1) {
                try {
                    $objectIds[] = new ObjectId($id);
                } catch (\Throwable) {
                    // Ignore invalid ObjectId conversions and keep string matching.
                }
            }
        }

        return [$stringIds, $objectIds];
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(StaticProfileType $model): array
    {
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
}
