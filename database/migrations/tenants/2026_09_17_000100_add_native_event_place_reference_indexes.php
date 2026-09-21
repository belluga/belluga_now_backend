<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;

return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const INDEXES = [
        'events' => [
            'idx_events_place_ref_type_native_id_v1' => [
                'place_ref.type' => 1,
                'place_ref._id' => 1,
            ],
        ],
        'event_occurrences' => [
            'idx_event_occurrences_place_ref_type_native_id_v1' => [
                'place_ref.type' => 1,
                'place_ref._id' => 1,
            ],
            'idx_event_occurrences_programming_place_ref_type_native_id_v1' => [
                'programming_items.place_ref.type' => 1,
                'programming_items.place_ref._id' => 1,
            ],
        ],
    ];

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();

        foreach (self::INDEXES as $collectionName => $indexes) {
            $collection = $database->selectCollection($collectionName);
            foreach ($indexes as $name => $keys) {
                $this->ensureIndex($collection, $name, $keys);
            }
        }
    }

    public function down(): void
    {
        $database = DB::connection('tenant')->getDatabase();

        foreach (self::INDEXES as $collectionName => $indexes) {
            $collection = $database->selectCollection($collectionName);
            foreach ($indexes as $name => $keys) {
                foreach ($collection->listIndexes() as $index) {
                    if ($index->getName() !== $name) {
                        continue;
                    }
                    if ($this->indexKeys($index->getKey()) !== $keys || $index->isUnique()) {
                        throw new RuntimeException("Refusing to drop incompatible index [{$name}].");
                    }
                    $collection->dropIndex($name);
                }
            }
        }
    }

    /** @param array<string, int> $keys */
    private function ensureIndex(Collection $collection, string $name, array $keys): void
    {
        foreach ($collection->listIndexes() as $index) {
            $existingKeys = $this->indexKeys($index->getKey());
            if ($index->getName() === $name) {
                if ($existingKeys !== $keys || $index->isUnique()) {
                    throw new RuntimeException("Refusing incompatible index [{$name}].");
                }

                return;
            }
            if ($existingKeys === $keys) {
                throw new RuntimeException(
                    "Refusing equivalent index [{$index->getName()}] for [{$name}].",
                );
            }
        }

        $collection->createIndex($keys, ['name' => $name]);
    }

    /** @return array<string, int> */
    private function indexKeys(mixed $keys): array
    {
        return json_decode(
            json_encode($keys, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
};
