<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $collection = DB::connection('tenant')->getDatabase()->selectCollection('events');
        $targets = [
            'publication.status_1_publication.publish_at_1__id_1' => ['publication.status' => 1, 'publication.publish_at' => 1, '_id' => 1],
            'publication.status_1_date_time_start_-1__id_1' => ['publication.status' => 1, 'date_time_start' => -1, '_id' => 1],
            'place_ref.type_1_place_ref.id_1_date_time_start_-1__id_1' => ['place_ref.type' => 1, 'place_ref.id' => 1, 'date_time_start' => -1, '_id' => 1],
            'location.mode_1_date_time_start_-1__id_1' => ['location.mode' => 1, 'date_time_start' => -1, '_id' => 1],
        ];
        $indexes = [];
        foreach ($collection->listIndexes() as $index) {
            $indexes[$index->getName()] = [
                'key' => json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
                'options' => self::indexOptions($index),
            ];
        }
        foreach ($targets as $name => $key) {
            if (isset($indexes[$name]) && ($indexes[$name]['key'] !== $key || self::hasNonDefaultOptions($indexes[$name]['options']))) throw new RuntimeException('tenant.events.index_conflicts_v1 found same-name conflict: '.$name);
            foreach ($indexes as $existingName => $existing) if ($existingName !== $name && $existing['key'] === $key) throw new RuntimeException('tenant.events.index_conflicts_v1 found same-key conflict: '.$existingName);
        }
        foreach ($targets as $name => $key) if (! isset($indexes[$name])) $collection->createIndex($key, ['name' => $name]);
    }

    public function down(): void {}

    /** @param array<string, mixed> $options */
    private static function hasNonDefaultOptions(array $options): bool
    {
        foreach (['unique', 'sparse', 'hidden', 'expireAfterSeconds', 'partialFilterExpression', 'collation'] as $option) {
            if (array_key_exists($option, $options) && $options[$option] !== false && $options[$option] !== null) return true;
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function indexOptions(object $index): array
    {
        $options = [];
        foreach (['unique', 'sparse', 'hidden', 'expireAfterSeconds', 'partialFilterExpression', 'collation'] as $option) {
            if (isset($index[$option])) $options[$option] = $index[$option];
        }

        return $options;
    }
};
