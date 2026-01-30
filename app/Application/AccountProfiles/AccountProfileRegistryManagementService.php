<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use Illuminate\Validation\ValidationException;

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
        $model = TenantProfileType::query()->where('type', $type)->first();
        if (! $model) {
            abort(404, 'Profile type not found.');
        }

        $entry = $this->mergeEntry($model, $payload, $type);
        $model->fill($entry);
        $model->save();

        return $this->toPayload($model);
    }

    public function delete(string $type): void
    {
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
            ],
        ];
    }

    /**
     * @param TenantProfileType $existing
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(TenantProfileType $existing, array $payload, string $type): array
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
                'is_favoritable' => array_key_exists('is_favoritable', $capabilities)
                    ? (bool) $capabilities['is_favoritable']
                    : (bool) ($currentCapabilities['is_favoritable'] ?? false),
                'is_poi_enabled' => array_key_exists('is_poi_enabled', $capabilities)
                    ? (bool) $capabilities['is_poi_enabled']
                    : (bool) ($currentCapabilities['is_poi_enabled'] ?? false),
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
            ],
        ];
    }
}
