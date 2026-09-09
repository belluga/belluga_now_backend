<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETIRED_CAPABILITY_INDEXES = [
        'account_profile_types' => [
            'idx_account_profile_types_capability_has_content_v1',
        ],
    ];

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();

        // Deployment fence: drain legacy writers before this cutover and run
        // this idempotent migration once more before admitting new traffic.
        // The final pass reconciles any retired keys written during rollout.
        // CR-04: unset stored Account Profile `content`; never copy it into `bio`.
        // Idempotent: reruns match no documents once the field is absent.
        if (Schema::hasCollection('account_profiles')) {
            $database
                ->selectCollection('account_profiles')
                ->updateMany(
                    ['content' => ['$exists' => true]],
                    ['$unset' => ['content' => '']]
                );
        }

        // CR-05: retire persisted Account Profile Type capability values and
        // indexes that mention has_content. Event/Static Asset collections are
        // never touched (CR-06).
        foreach (self::RETIRED_CAPABILITY_INDEXES as $collectionName => $indexNames) {
            if (! Schema::hasCollection($collectionName)) {
                continue;
            }
            $collection = $database->selectCollection($collectionName);
            $collection->updateMany(
                ['capabilities.has_content' => ['$exists' => true]],
                ['$unset' => ['capabilities.has_content' => '']]
            );
            $existingIndexes = [];
            foreach ($collection->listIndexes() as $index) {
                $name = (string) ($index['name'] ?? '');
                if ($name !== '') {
                    $existingIndexes[$name] = true;
                }
            }
            foreach ($indexNames as $indexName) {
                if ($existingIndexes[$indexName] ?? false) {
                    $collection->dropIndex($indexName);
                }
            }
        }
    }

    public function down(): void
    {
        // CR-07: atomic hard cut. Removed `content` values are not recoverable
        // and the retired capability value/index are intentionally not rebuilt.
    }
};
