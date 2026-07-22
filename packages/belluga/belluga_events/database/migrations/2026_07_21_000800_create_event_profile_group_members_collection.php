<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            return;
        }

        $collection = $connection->getDatabase()->selectCollection('event_profile_group_members');

        $collection->createIndex(
            [
                'tenant_id' => 1,
                'event_id' => 1,
                'owner_type' => 1,
                'owner_id' => 1,
                'doc_type' => 1,
                'group_order' => 1,
                '_id' => 1,
            ],
            ['name' => 'idx_event_profile_group_heads_v1']
        );

        $collection->createIndex(
            [
                'tenant_id' => 1,
                'event_id' => 1,
                'owner_type' => 1,
                'owner_id' => 1,
                'group_id' => 1,
                'doc_type' => 1,
                'relation_order' => 1,
                '_id' => 1,
            ],
            ['name' => 'idx_event_profile_group_members_v1']
        );

        $collection->createIndex(
            [
                'tenant_id' => 1,
                'event_id' => 1,
            ],
            ['name' => 'idx_event_profile_group_event_scope_v1']
        );
    }

    public function down(): void
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            return;
        }

        $collection = $connection->getDatabase()->selectCollection('event_profile_group_members');

        foreach ([
            'idx_event_profile_group_heads_v1',
            'idx_event_profile_group_members_v1',
            'idx_event_profile_group_event_scope_v1',
        ] as $indexName) {
            try {
                $collection->dropIndex($indexName);
            } catch (\Throwable) {
            }
        }
    }
};
