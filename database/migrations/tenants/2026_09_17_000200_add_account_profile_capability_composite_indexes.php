<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array<string, int>> */
    private const INDEXES = [
        'idx_account_profile_types_public_map_poi_v1' => [
            'capabilities.is_queryable.value' => 1,
            'capabilities.is_publicly_discoverable.value' => 1,
            'capabilities.is_map_poi_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
        'idx_account_profile_types_public_physical_host_v1' => [
            'capabilities.is_queryable.value' => 1,
            'capabilities.is_publicly_discoverable.value' => 1,
            'capabilities.is_physical_host_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
        'idx_account_profile_types_navigable_physical_host_v1' => [
            'capabilities.is_publicly_navigable.value' => 1,
            'capabilities.is_physical_host_enabled.value' => 1,
            'capabilities.location_policy.value' => 1,
            'type' => 1,
        ],
    ];

    public function up(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types');
        foreach (self::INDEXES as $name => $keys) {
            foreach ($collection->listIndexes() as $index) {
                if ($index->getName() === $name) {
                    if ($this->keys($index->getKey()) !== $keys || $index->isUnique()) {
                        throw new RuntimeException("Refusing incompatible index [{$name}].");
                    }
                    continue 2;
                }
                if ($this->keys($index->getKey()) === $keys) {
                    throw new RuntimeException("Refusing equivalent index [{$index->getName()}] for [{$name}].");
                }
            }
            $collection->createIndex($keys, ['name' => $name]);
        }
    }

    public function down(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profile_types');
        foreach (self::INDEXES as $name => $keys) {
            foreach ($collection->listIndexes() as $index) {
                if ($index->getName() !== $name) {
                    continue;
                }
                if ($this->keys($index->getKey()) !== $keys || $index->isUnique()) {
                    throw new RuntimeException("Refusing to drop incompatible index [{$name}].");
                }
                $collection->dropIndex($name);
            }
        }
    }

    /** @return array<string, int> */
    private function keys(mixed $keys): array
    {
        return json_decode(json_encode($keys, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
};
