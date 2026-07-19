<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

class AccountProfileRegistrySeeder
{
    private AccountProfileTypeCapabilityCatalog $capabilityCatalog;

    private AccountProfileTypeCapabilityRepairer $capabilityRepairer;

    private AccountProfileRegistryDefaultUpserter $defaultUpserter;

    public function __construct(
        ?AccountProfileTypeCapabilityCatalog $capabilityCatalog = null,
        ?AccountProfileTypeCapabilityRepairer $capabilityRepairer = null,
        ?AccountProfileRegistryDefaultUpserter $defaultUpserter = null,
    ) {
        $this->capabilityCatalog = $capabilityCatalog ?? new AccountProfileTypeCapabilityCatalog;
        $this->capabilityRepairer = $capabilityRepairer
            ?? new AccountProfileTypeCapabilityRepairer($this->capabilityCatalog);
        $this->defaultUpserter = $defaultUpserter ?? new AccountProfileRegistryDefaultUpserter;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaults(): array
    {
        $defaults = [
            [
                'type' => 'personal',
                'label' => 'Personal',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
            ],
            [
                'type' => 'artist',
                'label' => 'Artist',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
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
            ],
        ];

        return array_map(function (array $entry): array {
            $entry['capabilities'] = $this->capabilityCatalog->completeForPersistence(
                (string) $entry['type'],
            );

            return $entry;
        }, $defaults);
    }

    public function ensureDefaults(): void
    {
        foreach ($this->defaults() as $entry) {
            $outcome = $this->defaultUpserter->ensureDefault(
                $entry,
                new UTCDateTime((int) Carbon::now()->getTimestampMs()),
            );

            if (! $outcome->requiresExistingDefaultRepair()) {
                AccountProfileTypeSetProvider::bumpRevision();

                continue;
            }

            $type = trim((string) ($entry['type'] ?? ''));
            $existing = TenantProfileType::query()
                ->where('type', $type)
                ->firstOrFail();
            $this->repairDefaultCapabilities($existing);

            if ($outcome->requiresTypeSetInvalidation()) {
                AccountProfileTypeSetProvider::bumpRevision();
            }
        }
    }

    private function repairDefaultCapabilities(TenantProfileType $type): void
    {
        $modifiedCount = $this->capabilityRepairer->repairDocument(
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_types'),
            (string) $type->type,
            new UTCDateTime((int) Carbon::now()->getTimestampMs()),
        );

        if ($modifiedCount === 0) {
            return;
        }

        // Raw field repair bypasses model events; refresh before touching so projections receive the repaired map.
        $type->refresh()->touch();
    }
}
