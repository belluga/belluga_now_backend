<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Collection;

return new class extends Migration
{
    private const string INDEX_NAME = 'idx_invite_edges_sent_status_inviter_occurrence';

    public function up(): void
    {
        if (! Schema::hasTable('invite_edges')) {
            return;
        }

        $collection = $this->collection();
        $this->dropIndexIfExists($collection);

        $collection->createIndex(
            [
                'issued_by_user_id' => 1,
                'event_id' => 1,
                'occurrence_id' => 1,
                'created_at' => -1,
                '_id' => -1,
            ],
            [
                'name' => self::INDEX_NAME,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('invite_edges')) {
            return;
        }

        $this->dropIndexIfExists($this->collection());
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
};
