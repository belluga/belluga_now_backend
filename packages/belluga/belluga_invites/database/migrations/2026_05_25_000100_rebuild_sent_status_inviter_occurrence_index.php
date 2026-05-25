<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;

return new class extends Migration
{
    private const string INDEX_NAME = 'idx_invite_edges_sent_status_inviter_occurrence';
    private const array REBUILT_INDEX_KEYS = [
        'issued_by_user_id' => 1,
        'event_id' => 1,
        'occurrence_id' => 1,
        'created_at' => -1,
        '_id' => -1,
    ];
    private const array PREVIOUS_INDEX_KEYS = [
        'issued_by_user_id' => 1,
        'event_id' => 1,
        'occurrence_id' => 1,
        'inviter_principal.kind' => 1,
        'inviter_principal.principal_id' => 1,
        'created_at' => -1,
        '_id' => -1,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('invite_edges')) {
            return;
        }

        $collection = $this->collection();
        $this->dropIndexIfExists($collection);

        $this->createIndex($collection, self::REBUILT_INDEX_KEYS);
    }

    public function down(): void
    {
        if (! Schema::hasTable('invite_edges')) {
            return;
        }

        $collection = $this->collection();
        $this->dropIndexIfExists($collection);
        $this->createIndex($collection, self::PREVIOUS_INDEX_KEYS);
    }

    private function collection(): Collection
    {
        return DB::connection('tenant')->getCollection('invite_edges');
    }

    private function dropIndexIfExists(Collection $collection): void
    {
        try {
            $collection->dropIndex(self::INDEX_NAME);
        } catch (Throwable) {
            // Fresh and partially migrated databases may not have this index yet.
        }
    }

    /**
     * @param  array<string, int>  $keys
     */
    private function createIndex(Collection $collection, array $keys): void
    {
        $collection->createIndex(
            $keys,
            [
                'name' => self::INDEX_NAME,
            ],
        );
    }
};
