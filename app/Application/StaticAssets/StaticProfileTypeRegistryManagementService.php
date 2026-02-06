<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Tenants\StaticProfileType;
use Illuminate\Validation\ValidationException;

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

        $entry = $this->mergeEntry($model, $payload, $type);
        $model->fill($entry);
        $model->save();

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
    private function mergeEntry(StaticProfileType $existing, array $payload, string $type): array
    {
        $capabilities = $payload['capabilities'] ?? [];
        $currentCapabilities = $existing->capabilities ?? [];

        return [
            'type' => $type,
            'label' => array_key_exists('label', $payload)
                ? trim((string) $payload['label'])
                : (string) ($existing->label ?? ''),
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

    /**
     * @return array<string, mixed>
     */
    private function toPayload(StaticProfileType $model): array
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
