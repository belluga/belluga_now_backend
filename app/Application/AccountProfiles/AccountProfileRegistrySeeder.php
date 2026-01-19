<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantSettings;

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
                'parent_type' => null,
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
            [
                'type' => 'business',
                'label' => 'Business',
                'parent_type' => null,
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
            [
                'type' => 'influencer',
                'label' => 'Influencer',
                'parent_type' => 'personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
            [
                'type' => 'curator',
                'label' => 'Curator',
                'parent_type' => 'personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
            [
                'type' => 'artist',
                'label' => 'Artist',
                'parent_type' => 'personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
            [
                'type' => 'venue',
                'label' => 'Venue',
                'parent_type' => 'business',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
            [
                'type' => 'experience_provider',
                'label' => 'Experience Provider',
                'parent_type' => 'business',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => false,
                ],
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        $settings = TenantSettings::current();
        $registry = $settings?->profile_type_registry ?? [];

        $types = collect($registry)
            ->map(static fn (array $entry): ?string => $entry['type'] ?? null)
            ->filter()
            ->all();

        if (in_array('personal', $types, true)) {
            return;
        }

        $merged = array_merge($registry, $this->defaults());

        if (! $settings) {
            TenantSettings::create([
                'profile_type_registry' => $merged,
            ]);

            return;
        }

        $settings->profile_type_registry = $merged;
        $settings->save();
    }
}
