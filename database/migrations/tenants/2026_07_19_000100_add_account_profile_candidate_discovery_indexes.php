<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

return new class extends Migration
{
    public function up(): void
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        foreach ([
            'idx_account_profile_types_queryable_candidates_v1',
            'idx_account_profile_types_contact_channels_v1',
        ] as $legacyIndex) {
            try {
                $collection->dropIndex($legacyIndex);
            } catch (\Throwable) {
            }
        }

        foreach ((new AccountProfileTypeIndexManifest)->definitions() as $definition) {
            if (! in_array($definition['name'], [
                'idx_account_profile_types_candidate_queryable_v1',
                'idx_account_profile_types_candidate_contact_capable_v1',
            ], true)) {
                continue;
            }

            try {
                $collection->createIndex($definition['keys'], [
                    'name' => $definition['name'],
                    'collation' => $definition['collation'],
                ]);
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
    }
};
