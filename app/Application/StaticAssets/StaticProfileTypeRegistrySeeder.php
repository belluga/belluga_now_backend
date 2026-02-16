<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Models\Tenants\StaticProfileType;

class StaticProfileTypeRegistrySeeder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaults(): array
    {
        return [
            [
                'type' => 'poi',
                'label' => 'POI',
                'map_category' => 'poi',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_poi_enabled' => true,
                    'has_bio' => true,
                    'has_taxonomies' => true,
                    'has_avatar' => true,
                    'has_cover' => true,
                    'has_content' => true,
                ],
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        if (StaticProfileType::query()->where('type', 'poi')->exists()) {
            return;
        }

        foreach ($this->defaults() as $entry) {
            StaticProfileType::create($entry);
        }
    }
}
