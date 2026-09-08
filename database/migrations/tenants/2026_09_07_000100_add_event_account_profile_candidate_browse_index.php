<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    private const INDEX_NAME = 'idx_account_profiles_event_candidate_browse_v1';

    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profiles');

        $this->dropIfPresent($collection);
        $collection->createIndex(
            ['deleted_at' => 1, 'profile_type' => 1, 'display_name' => 1, '_id' => 1],
            [
                'name' => self::INDEX_NAME,
                'collation' => ['locale' => 'simple'],
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $this->dropIfPresent(
            DB::connection('tenant')->getDatabase()->selectCollection('account_profiles'),
        );
    }

    private function dropIfPresent(\MongoDB\Collection $collection): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== self::INDEX_NAME) {
                continue;
            }

            try {
                $collection->dropIndex(self::INDEX_NAME);
            } catch (CommandException $exception) {
                if ($exception->getCode() !== 27) {
                    throw $exception;
                }
            }

            return;
        }
    }
};
