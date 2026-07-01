<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;

return new class extends Migration
{
    private const string INDEX_NAME = 'idx_event_occurrences_public_agenda_party_ref_v1';

    private const array INDEX_KEYS = [
        'deleted_at' => 1,
        'is_event_published' => 1,
        'event_parties.party_ref_id' => 1,
        'effective_ends_at' => 1,
        'starts_at' => 1,
        '_id' => 1,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('event_occurrences')) {
            return;
        }

        $collection = $this->collection();
        $this->dropIndexIfExists($collection);
        $this->createIndex($collection, self::INDEX_KEYS);
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_occurrences')) {
            return;
        }

        $this->dropIndexIfExists($this->collection());
    }

    private function collection(): Collection
    {
        return DB::connection('tenant')->getCollection('event_occurrences');
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
