<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

return new class extends Migration
{
    public function up(): void
    {
        $collection = $this->collection();

        $this->dropIndexIfExists($collection, 'user_id_1');
        $this->dropIndexIfExists($collection, 'token_1');

        $collection->deleteMany([
            '$or' => [
                ['slot_key' => ['$exists' => false]],
                ['slot_key' => null],
                ['slot_key' => ''],
            ],
        ]);

        $collection->createIndex(['slot_key' => 1], ['unique' => true]);
        $collection->createIndex(['broker' => 1]);
        $collection->createIndex(['user_id' => 1]);
        $collection->createIndex(['user_id_string' => 1]);
        $collection->createIndex(['token_lookup_hash' => 1]);
        $collection->createIndex(['expires_at' => 1]);
    }

    public function down(): void
    {
        $collection = $this->collection();

        foreach ([
            'slot_key_1',
            'broker_1',
            'user_id_1',
            'user_id_string_1',
            'token_lookup_hash_1',
            'expires_at_1',
        ] as $indexName) {
            $this->dropIndexIfExists($collection, $indexName);
        }

        $collection->createIndex(['user_id' => 1], [
            'name' => 'user_id_1',
            'unique' => true,
        ]);
        $collection->createIndex(['token' => 1], ['name' => 'token_1']);
    }

    private function collection(): Collection
    {
        return DB::connection('landlord')->getMongoDB()->selectCollection('password_reset_tokens');
    }

    private function dropIndexIfExists(Collection $collection, string $indexName): void
    {
        try {
            $collection->dropIndex($indexName);
        } catch (Throwable) {
            // Index absence is expected on fresh databases or already-repaired environments.
        }
    }
};
