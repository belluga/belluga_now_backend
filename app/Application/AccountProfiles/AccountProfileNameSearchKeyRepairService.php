<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;

final class AccountProfileNameSearchKeyRepairService
{
    private const SAMPLE_LIMIT = 5;

    /**
     * @return array<string, mixed>
     */
    public function run(bool $execute, int $chunkSize = 200): array
    {
        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Tenant context not available.');
        }

        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profiles');
        $missingFilter = [
            '$or' => [
                ['name_search_key' => ['$exists' => false]],
                ['name_search_key' => null],
                ['name_search_key' => ['$regex' => '^\s*$']],
            ],
        ];

        $missingCount = 0;
        $repairableCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $samples = [];

        $cursor = $collection->find($missingFilter, [
            'sort' => ['_id' => 1],
            'projection' => [
                '_id' => 1,
                'display_name' => 1,
            ],
            'batchSize' => max(1, min($chunkSize, 1000)),
        ]);

        foreach ($cursor as $document) {
            $missingCount++;

            $displayName = trim((string) ($document['display_name'] ?? ''));
            $searchKey = AccountProfileNameSearchKey::fromDisplayName($displayName);

            if ($searchKey === '') {
                $skippedCount++;
                if (count($samples) < self::SAMPLE_LIMIT) {
                    $samples[] = [
                        'id' => (string) ($document['_id'] ?? ''),
                        'display_name' => $displayName,
                        'status' => 'skipped_empty_normalized_key',
                    ];
                }

                continue;
            }

            $repairableCount++;
            if (count($samples) < self::SAMPLE_LIMIT) {
                $samples[] = [
                    'id' => (string) ($document['_id'] ?? ''),
                    'display_name' => $displayName,
                    'name_search_key' => $searchKey,
                    'status' => $execute ? 'updated' : 'pending',
                ];
            }

            if (! $execute) {
                continue;
            }

            $collection->updateOne(
                ['_id' => $document['_id']],
                ['$set' => ['name_search_key' => $searchKey]],
            );
            $updatedCount++;
        }

        return [
            'tenant_slug' => (string) $tenant->slug,
            'tenant_subdomain' => (string) $tenant->subdomain,
            'dry_run' => ! $execute,
            'missing_name_search_keys' => $missingCount,
            'repairable_profiles' => $repairableCount,
            'updated_profiles' => $updatedCount,
            'skipped_profiles' => $skippedCount,
            'sample' => $samples,
        ];
    }
}
