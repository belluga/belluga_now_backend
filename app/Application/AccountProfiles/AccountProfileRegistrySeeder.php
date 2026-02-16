<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;

class AccountProfileRegistrySeeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            [
                'type' => 'personal',
                'label' => 'Personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => false,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
            ],
            [
                'type' => 'artist',
                'label' => 'Artist',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
            ],
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                    'has_content' => false,
                ],
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        if (TenantProfileType::query()->where('type', 'personal')->exists()) {
            return;
        }

        foreach ($this->defaults() as $entry) {
            TenantProfileType::create($entry);
        }
    }
}
