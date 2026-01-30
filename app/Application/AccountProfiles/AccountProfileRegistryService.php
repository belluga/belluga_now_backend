<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use App\Application\AccountProfiles\AccountProfileRegistryManagementService;

class AccountProfileRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return TenantProfileType::query()
            ->orderBy('type')
            ->get()
            ->map(static function (TenantProfileType $type): array {
                return [
                    'type' => $type->type,
                    'label' => $type->label,
                    'allowed_taxonomies' => array_values(array_filter(
                        is_array($type->allowed_taxonomies ?? null)
                            ? $type->allowed_taxonomies
                            : [],
                        static fn ($value): bool => is_string($value) && $value !== ''
                    )),
                    'capabilities' => [
                        'is_favoritable' => (bool) ($type->capabilities['is_favoritable'] ?? false),
                        'is_poi_enabled' => (bool) ($type->capabilities['is_poi_enabled'] ?? false),
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
}
