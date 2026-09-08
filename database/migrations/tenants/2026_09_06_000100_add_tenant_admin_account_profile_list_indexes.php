<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    /**
     * These indexes are aligned with the final Profile-rooted admin list:
     * browse sorts by the page order, while each search branch has its own
     * leading indexed field. Search indexes are intentionally non-partial:
     * MongoDB can then combine both branches even though the shared canonical
     * predicate carries defensive type guards for nullable legacy fields.
     *
     * @var array<string, array{key: array<string, int>, partial: array<string, mixed>}>
     */
    private const INDEXES = [
        'idx_account_profiles_admin_browse_v1' => [
            'key' => ['deleted_at' => 1, 'created_at' => -1, '_id' => -1],
            'partial' => [],
        ],
        'idx_account_profiles_admin_name_search_v1' => [
            'key' => ['deleted_at' => 1, 'name_search_key' => 1, 'created_at' => -1, '_id' => -1],
            'partial' => [],
        ],
        'idx_account_profiles_admin_terms_search_v1' => [
            'key' => ['deleted_at' => 1, 'search_terms' => 1, 'created_at' => -1, '_id' => -1],
            'partial' => [],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles');

        foreach (self::INDEXES as $name => $definition) {
            $this->dropIfPresent($collection, $name);
            $options = [
                'name' => $name,
                'collation' => ['locale' => 'simple'],
            ];
            if ($definition['partial'] !== []) {
                $options['partialFilterExpression'] = $definition['partial'];
            }
            $collection->createIndex($definition['key'], $options);
        }
    }

    public function down(): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles');

        foreach (array_keys(self::INDEXES) as $name) {
            $this->dropIfPresent($collection, $name);
        }
    }

    private function dropIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== $name) {
                continue;
            }

            try {
                $collection->dropIndex($name);
            } catch (CommandException $exception) {
                if ($exception->getCode() !== 27) {
                    throw $exception;
                }
            }

            return;
        }
    }
};
