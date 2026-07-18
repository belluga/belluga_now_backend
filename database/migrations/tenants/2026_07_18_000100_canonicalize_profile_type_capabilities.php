<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityRepairer;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\UTCDateTime;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('account_profile_types');

        $catalog = new AccountProfileTypeCapabilityCatalog;

        (new AccountProfileTypeCapabilityRepairer($catalog))->repairCollection(
            $collection,
            new UTCDateTime((int) Carbon::now()->getTimestampMs()),
        );

        $this->provisionIndexes($collection, $catalog);
    }

    public function down(): void
    {
        // Capability completion is additive and must not erase persisted tenant intent.
    }

    private function provisionIndexes(
        \MongoDB\Collection $collection,
        AccountProfileTypeCapabilityCatalog $catalog,
    ): void {
        foreach ([
            'capabilities.is_favoritable_1',
            'capabilities.is_poi_enabled_1',
            'idx_account_profile_types_inviteable_v1',
            'idx_account_profile_types_public_catalog_v1',
        ] as $legacyIndex) {
            $this->dropIndexIfPresent($collection, $legacyIndex);
        }

        foreach ($catalog->capabilityIndexDefinitions() as $definition) {
            $collection->createIndex($definition['keys'], ['name' => $definition['name']]);
        }

        foreach (TenantProfileType::capabilityQueryIndexDefinitions() as $definition) {
            $collection->createIndex($definition['keys'], ['name' => $definition['name']]);
        }
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                $collection->dropIndex($name);

                return;
            }
        }
    }
};
