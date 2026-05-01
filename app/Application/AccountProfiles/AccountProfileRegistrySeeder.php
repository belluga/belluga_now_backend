<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

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
                'poi_visual' => null,
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_inviteable' => true,
                    'is_publicly_discoverable' => false,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
            ],
            [
                'type' => 'artist',
                'label' => 'Artist',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_inviteable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
            ],
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => [],
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'place',
                    'color' => '#E53935',
                ],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_inviteable' => false,
                    'is_publicly_discoverable' => true,
                    'is_poi_enabled' => true,
                    'has_content' => false,
                ],
            ],
        ];
    }

    public function ensureDefaults(): void
    {
        foreach ($this->defaults() as $entry) {
            $type = trim((string) ($entry['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $existing = TenantProfileType::query()
                ->where('type', $type)
                ->first();

            if (! $existing instanceof TenantProfileType) {
                TenantProfileType::create($entry);

                continue;
            }

            $this->repairDefaultCapabilities($existing, $entry);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function repairDefaultCapabilities(
        TenantProfileType $type,
        array $entry,
    ): void {
        $current = $this->arrayFrom($type->capabilities ?? []);
        $defaults = $this->arrayFrom($entry['capabilities'] ?? []);
        $next = $current + $defaults;

        if ((string) $type->type === 'personal') {
            $next['is_favoritable'] = true;
            $next['is_inviteable'] = true;
            $next['is_publicly_discoverable'] = false;
        }

        if ($next === $current) {
            return;
        }

        $type->capabilities = $next;
        $type->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
}
