<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\MapPois\MapPoiProjectionRefService;
use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Jobs\DeleteMapPoiByRefJob;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountProfileRegistryManagementService
{
    public function __construct(
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly MapPoiProjectionRefService $mapPoiProjectionRefs,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        if (TenantProfileType::query()->where('type', $type)->exists()) {
            throw ValidationException::withMessages([
                'type' => ['Profile type already exists.'],
            ]);
        }

        $entry = $this->buildEntry($payload, $type);

        $model = TenantProfileType::create($entry);

        return $this->toPayload($model);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $type, array $payload): array
    {
        $type = trim($type);
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
        }

        $nextType = array_key_exists('type', $payload)
            ? trim((string) $payload['type'])
            : (string) ($model->type ?? '');
        $currentType = (string) ($model->type ?? '');

        if ($nextType !== $currentType) {
            if (TenantProfileType::query()->where('type', $nextType)->exists()) {
                throw ValidationException::withMessages([
                    'type' => ['Profile type already exists.'],
                ]);
            }
        }

        $entry = $this->mergeEntry($model, $payload, $nextType);
        $currentCapabilities = is_array($model->capabilities ?? null)
            ? $model->capabilities
            : [];
        $currentPoiEnabled = (bool) ($currentCapabilities['is_poi_enabled'] ?? false);
        $nextCapabilities = is_array($entry['capabilities'] ?? null)
            ? $entry['capabilities']
            : [];
        $nextPoiEnabled = (bool) ($nextCapabilities['is_poi_enabled'] ?? false);
        $currentPoiVisual = $this->poiVisualNormalizer->normalize($model->poi_visual ?? null);
        $nextPoiVisual = $this->poiVisualNormalizer->normalize($entry['poi_visual'] ?? null);
        $poiVisualChanged = $currentPoiVisual !== $nextPoiVisual;

        try {
            $model->fill($entry);
            $model->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'type' => ['Profile type already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'profile_type' => ['Something went wrong when trying to update the profile type.'],
            ]);
        }

        $forcedCheckpoint = $this->toCheckpoint($model->updated_at ?? null);
        $shouldRefreshMapProjection = $nextType !== $currentType
            || $currentPoiEnabled !== $nextPoiEnabled
            || $poiVisualChanged;

        if ($shouldRefreshMapProjection) {
            $queryType = $nextType === $currentType ? $nextType : $currentType;
            $profileIds = AccountProfile::query()
                ->where('profile_type', $queryType)
                ->get(['_id'])
                ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
                ->all();

            if ($nextType !== $currentType && $profileIds !== []) {
                AccountProfile::query()
                    ->where('profile_type', $currentType)
                    ->update(['profile_type' => $nextType]);
            }

            if ($profileIds !== []) {
                if (! $nextPoiEnabled) {
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $profileIds,
                        static function (string $profileId): void {
                            DeleteMapPoiByRefJob::dispatch('account_profile', $profileId);
                        },
                    );
                } else {
                    $checkpoint = $forcedCheckpoint > 0 ? $forcedCheckpoint : null;
                    $this->mapPoiProjectionRefs->dispatchForEachRefId(
                        $profileIds,
                        static function (string $profileId) use ($checkpoint): void {
                            UpsertMapPoiFromAccountProfileJob::dispatch($profileId, $checkpoint);
                        },
                    );
                }
            }
        }

        return $this->toPayload($model->fresh() ?? $model);
    }

    public function previewDisableProjectionCount(string $type): int
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return 0;
        }

        $profileIds = AccountProfile::query()
            ->where('profile_type', $normalizedType)
            ->get(['_id'])
            ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
            ->all();

        return $this->mapPoiProjectionRefs->countByRefType('account_profile', $profileIds);
    }

    public function delete(string $type): void
    {
        $type = trim($type);
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
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

        return [
            'type' => $type,
            'label' => trim((string) ($payload['label'] ?? '')),
            'allowed_taxonomies' => $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? []),
            'poi_visual' => $this->poiVisualNormalizer->normalize($payload['poi_visual'] ?? null),
            'capabilities' => [
                'is_favoritable' => (bool) ($capabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => (bool) ($capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($capabilities['has_bio'] ?? false),
                'has_content' => (bool) ($capabilities['has_content'] ?? false),
                'has_taxonomies' => (bool) ($capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($capabilities['has_cover'] ?? false),
                'has_events' => (bool) ($capabilities['has_events'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(TenantProfileType $existing, array $payload, string $resolvedType): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $currentCapabilities = $existing->capabilities ?? [];

        return [
            'type' => $resolvedType,
            'label' => array_key_exists('label', $payload)
                ? trim((string) $payload['label'])
                : (string) ($existing->label ?? ''),
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? [])
                : $this->normalizeTaxonomies($existing->allowed_taxonomies ?? []),
            'poi_visual' => array_key_exists('poi_visual', $payload)
                ? $this->poiVisualNormalizer->normalize($payload['poi_visual'] ?? null)
                : $this->poiVisualNormalizer->normalize($existing->poi_visual ?? null),
            'capabilities' => [
                'is_favoritable' => array_key_exists('is_favoritable', $capabilities)
                    ? (bool) $capabilities['is_favoritable']
                    : (bool) ($currentCapabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => array_key_exists('is_poi_enabled', $capabilities)
                    ? (bool) $capabilities['is_poi_enabled']
                    : (bool) ($currentCapabilities['is_poi_enabled'] ?? false),
                'has_bio' => array_key_exists('has_bio', $capabilities)
                    ? (bool) $capabilities['has_bio']
                    : (bool) ($currentCapabilities['has_bio'] ?? false),
                'has_content' => array_key_exists('has_content', $capabilities)
                    ? (bool) $capabilities['has_content']
                    : (bool) ($currentCapabilities['has_content'] ?? false),
                'has_taxonomies' => array_key_exists('has_taxonomies', $capabilities)
                    ? (bool) $capabilities['has_taxonomies']
                    : (bool) ($currentCapabilities['has_taxonomies'] ?? false),
                'has_avatar' => array_key_exists('has_avatar', $capabilities)
                    ? (bool) $capabilities['has_avatar']
                    : (bool) ($currentCapabilities['has_avatar'] ?? false),
                'has_cover' => array_key_exists('has_cover', $capabilities)
                    ? (bool) $capabilities['has_cover']
                    : (bool) ($currentCapabilities['has_cover'] ?? false),
                'has_events' => array_key_exists('has_events', $capabilities)
                    ? (bool) $capabilities['has_events']
                    : (bool) ($currentCapabilities['has_events'] ?? false),
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
    private function toPayload(TenantProfileType $model): array
    {
        return [
            'type' => (string) $model->type,
            'label' => (string) $model->label,
            'allowed_taxonomies' => array_values(array_filter(
                is_array($model->allowed_taxonomies ?? null)
                    ? $model->allowed_taxonomies
                    : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'poi_visual' => $this->poiVisualNormalizer->normalize($model->poi_visual ?? null),
            'capabilities' => [
                'is_favoritable' => (bool) ($model->capabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => (bool) ($model->capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($model->capabilities['has_bio'] ?? false),
                'has_content' => (bool) ($model->capabilities['has_content'] ?? false),
                'has_taxonomies' => (bool) ($model->capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($model->capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($model->capabilities['has_cover'] ?? false),
                'has_events' => (bool) ($model->capabilities['has_events'] ?? false),
            ],
        ];
    }
}
