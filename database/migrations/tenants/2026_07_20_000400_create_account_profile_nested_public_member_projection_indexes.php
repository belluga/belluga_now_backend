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

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_nested_public_member_projection');

        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_slug_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_parent_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_member_refresh_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_parent_type_refresh_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_member_type_refresh_v1');

        $collection->createIndex(
            ['tenant_id' => 1, 'parent_slug' => 1, 'group_id' => 1, 'doc_type_rank' => 1, 'raw_position' => 1, '_id' => 1],
            ['name' => 'idx_nested_public_projection_slug_group_page_v1'],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'parent_profile_id' => 1, 'group_id' => 1, 'doc_type_rank' => 1, 'raw_position' => 1, '_id' => 1],
            ['name' => 'idx_nested_public_projection_parent_group_page_v1'],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'member_profile_id' => 1, 'doc_type_rank' => 1, '_id' => 1],
            [
                'name' => 'idx_nested_public_projection_member_refresh_v1',
                'partialFilterExpression' => ['doc_type' => 'member_edge'],
            ],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'parent_profile_type' => 1, 'doc_type_rank' => 1, '_id' => 1],
            ['name' => 'idx_nested_public_projection_parent_type_refresh_v1'],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'member_profile_type' => 1, 'doc_type_rank' => 1, '_id' => 1],
            [
                'name' => 'idx_nested_public_projection_member_type_refresh_v1',
                'partialFilterExpression' => ['doc_type' => 'member_edge'],
            ],
        );
    }

    public function down(): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_nested_public_member_projection');

        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_slug_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_parent_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_member_refresh_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_parent_type_refresh_v1');
        $this->dropIndexIfPresent($collection, 'idx_nested_public_projection_member_type_refresh_v1');
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
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
    }
};
