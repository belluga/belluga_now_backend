<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;

return new class extends Migration
{
    private const string INDEX_NAME = 'idx_event_occurrences_public_agenda_geo_v1';

    private const array INDEX_KEYS = [
        'deleted_at' => 1,
        'is_event_published' => 1,
        'geo_location' => '2dsphere',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('event_occurrences')) {
            return;
        }

        $collection = $this->collection();
        $this->dropIndexIfExists($collection);
        $collection->createIndex(self::INDEX_KEYS, ['name' => self::INDEX_NAME]);
    }

    public function down(): void
    {
        if (Schema::hasTable('event_occurrences')) {
            $this->dropIndexIfExists($this->collection());
        }
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
            // Fresh and partially migrated tenant databases do not have this index.
        }
    }
};
