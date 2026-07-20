<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $collection = DB::connection('tenant')->getDatabase()->selectCollection('account_profiles');
        $this->dropIndexIfPresent($collection, 'idx_account_profiles_public_name_v1');
        $collection->createIndex(
            [
                'visibility' => 1,
                'is_active' => 1,
                'deleted_at' => 1,
                'profile_type' => 1,
                'name_search_key' => 1,
                '_id' => 1,
            ],
            [
                'name' => 'idx_account_profiles_public_name_v1',
                'collation' => ['locale' => 'simple'],
                // Keep the name index out of empty-search planning; it exists only for explicit name-key reads.
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
        $this->dropIndexIfPresent($collection, 'idx_account_profiles_public_name_v1');
        $collection->createIndex(
            [
                'visibility' => 1,
                'is_active' => 1,
                'deleted_at' => 1,
                'profile_type' => 1,
                'name_search_key' => 1,
                '_id' => 1,
            ],
            [
                'name' => 'idx_account_profiles_public_name_v1',
                'collation' => ['locale' => 'simple'],
            ],
        );
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        try {
            $collection->dropIndex($name);
        } catch (CommandException $exception) {
            if ($exception->getCode() === 27) {
                return;
            }

            throw $exception;
        }
    }
};
