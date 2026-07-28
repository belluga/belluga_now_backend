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
            ->selectCollection('accounts_nested');

        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_parent_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_parent_scan_v1');
        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_member_lookup_v1');

        $collection->createIndex(
            ['tenant_id' => 1, 'parent_type' => 1, 'parent_id' => 1, 'group_key' => 1, 'doc_type' => 1, 'item_order' => 1, '_id' => 1],
            ['name' => 'idx_accounts_nested_parent_group_page_v1'],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'parent_type' => 1, 'parent_id' => 1, 'group_key' => 1, 'doc_type' => 1, '_id' => 1],
            ['name' => 'idx_accounts_nested_parent_scan_v1'],
        );
        $collection->createIndex(
            ['tenant_id' => 1, 'nested_profile.id' => 1, 'doc_type' => 1, '_id' => 1],
            [
                'name' => 'idx_accounts_nested_member_lookup_v1',
                'partialFilterExpression' => ['doc_type' => 'member_row'],
            ],
        );
    }

    public function down(): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('accounts_nested');

        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_parent_group_page_v1');
        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_parent_scan_v1');
        $this->dropIndexIfPresent($collection, 'idx_accounts_nested_member_lookup_v1');
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
