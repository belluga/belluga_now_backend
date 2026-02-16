<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountProfileRegistryManagementService
{
    /**
     * @param array<string, mixed> $payload
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
     * @param array<string, mixed> $payload
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

        if ($nextType !== $currentType) {
            $profileIds = AccountProfile::query()
                ->where('profile_type', $currentType)
                ->get(['_id'])
                ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
                ->all();

            if ($profileIds !== []) {
                AccountProfile::query()
                    ->where('profile_type', $currentType)
                    ->update(['profile_type' => $nextType]);

                [$stringRefIds, $objectRefIds] = $this->splitMapPoiRefIds($profileIds);

                if ($stringRefIds !== []) {
                    MapPoi::query()
                        ->where('ref_type', 'account_profile')
                        ->whereIn('ref_id', $stringRefIds)
                        ->update(['category' => $nextType]);
                }

                if ($objectRefIds !== []) {
                    MapPoi::query()
                        ->where('ref_type', 'account_profile')
                        ->whereIn('ref_id', $objectRefIds)
                        ->update(['category' => $nextType]);
                }
            }
        }

        return $this->toPayload($model);
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload, string $type): array
    {
        $capabilities = $payload['capabilities'] ?? [];

        return [
            'type' => $type,
            'label' => trim((string) ($payload['label'] ?? '')),
            'allowed_taxonomies' => $this->normalizeTaxonomies($payload['allowed_taxonomies'] ?? []),
            'capabilities' => [
                'is_favoritable' => (bool) ($capabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => (bool) ($capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($capabilities['has_bio'] ?? false),
                'has_taxonomies' => (bool) ($capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($capabilities['has_cover'] ?? false),
                'has_events' => (bool) ($capabilities['has_events'] ?? false),
            ],
        ];
    }

    /**
     * @param TenantProfileType $existing
     * @param array<string, mixed> $payload
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
            'capabilities' => [
                'is_favoritable' => (bool) ($model->capabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => (bool) ($model->capabilities['is_poi_enabled'] ?? false),
                'has_bio' => (bool) ($model->capabilities['has_bio'] ?? false),
                'has_taxonomies' => (bool) ($model->capabilities['has_taxonomies'] ?? false),
                'has_avatar' => (bool) ($model->capabilities['has_avatar'] ?? false),
                'has_cover' => (bool) ($model->capabilities['has_cover'] ?? false),
                'has_events' => (bool) ($model->capabilities['has_events'] ?? false),
            ],
        ];
    }
}
