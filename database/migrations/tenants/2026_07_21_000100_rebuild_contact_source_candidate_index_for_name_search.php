<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    private const INDEX_NAME = 'idx_account_profiles_contact_source_candidates_v1';

    private const INDEX_KEY = [
        'contact_mode' => 1,
        'is_active' => 1,
        'deleted_at' => 1,
        'profile_type' => 1,
        'name_search_key' => 1,
        '_id' => 1,
    ];

    private const LEGACY_INDEX_KEY = [
        'contact_mode' => 1,
        'is_active' => 1,
        'deleted_at' => 1,
        'profile_type' => 1,
        'display_name' => 1,
        '_id' => 1,
    ];

    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profiles');
        $this->dropIndexIfPresent($collection, self::INDEX_NAME, self::INDEX_KEY);
        $this->dropIndexIfPresent($collection, self::INDEX_NAME, self::LEGACY_INDEX_KEY);
        $collection->createIndex(
            self::INDEX_KEY,
            [
                'name' => self::INDEX_NAME,
                'collation' => ['locale' => 'simple'],
                'partialFilterExpression' => [
                    'name_search_key' => ['$exists' => true],
                ],
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profiles');
        $this->dropIndexIfPresent($collection, self::INDEX_NAME, self::INDEX_KEY);
        $collection->createIndex(
            self::LEGACY_INDEX_KEY,
            [
                'name' => self::INDEX_NAME,
                'collation' => ['locale' => 'simple'],
            ],
        );
    }

    /**
     * @param  array<string, int>  $key
     */
    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name, array $key): void
    {
        foreach ($collection->listIndexes() as $index) {
            $shouldDrop = $index->getName() === $name
                || $this->normalizeKey($index->getKey()) === $this->normalizeKey($key);
            if (! $shouldDrop) {
                continue;
            }

            $this->dropIndexIgnoringMissing($collection, $index->getName());
        }
    }

    private function dropIndexIgnoringMissing(\MongoDB\Collection $collection, string $name): void
    {
        try {
            $collection->dropIndex($name);
        } catch (CommandException $exception) {
            if ($exception->getCode() !== 27) {
                throw $exception;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array<string, int>
     */
    private function normalizeKey(array $key): array
    {
        return array_map(static fn (mixed $direction): int => (int) $direction, $key);
    }
};
