<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    private const INDEXES = [
        'accounts_nested' => [
            'idx_accounts_nested_group_member_browse_v2' => [
                'key' => ['parent_type' => 1, 'parent_id' => 1, 'group_key' => 1, 'item_order' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'member_row'],
            ],
            'idx_accounts_nested_group_member_name_search_v1' => [
                'key' => ['parent_type' => 1, 'parent_id' => 1, 'group_key' => 1, 'nested_profile.search_key' => 1, 'item_order' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'member_row', 'nested_profile.search_key' => ['$type' => 'string']],
            ],
            'idx_accounts_nested_group_member_terms_search_v1' => [
                'key' => ['parent_type' => 1, 'parent_id' => 1, 'group_key' => 1, 'nested_profile.search_terms' => 1, 'item_order' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'member_row', 'nested_profile.search_terms' => ['$type' => 'array']],
            ],
            'idx_accounts_nested_event_public_tab_head_v1' => [
                'key' => ['parent_type' => 1, 'event_id' => 1, 'doc_type' => 1, 'parent_id' => 1, 'group_order' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'group_head', 'parent_type' => 'event_occurrence'],
            ],
            'idx_accounts_nested_event_member_count_v1' => [
                'key' => ['parent_type' => 1, 'event_id' => 1, 'parent_id' => 1, 'group_key' => 1, 'nested_profile.id' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'member_row', 'parent_type' => 'event_occurrence'],
            ],
            'idx_accounts_nested_member_profile_refresh_v2' => [
                'key' => ['parent_type' => 1, 'nested_profile.id' => 1, '_id' => 1],
                'partial' => ['doc_type' => 'member_row', 'parent_type' => 'account_profile'],
            ],
        ],
        'account_profiles' => [
            'idx_account_profiles_public_terms_v1' => [
                'key' => ['visibility' => 1, 'is_active' => 1, 'deleted_at' => 1, 'profile_type' => 1, 'search_terms' => 1, 'name_search_key' => 1, '_id' => 1],
                'partial' => ['search_terms' => ['$type' => 'array']],
            ],
            'idx_account_profiles_queryable_terms_v1' => [
                'key' => ['is_active' => 1, 'deleted_at' => 1, 'profile_type' => 1, 'search_terms' => 1, 'name_search_key' => 1, '_id' => 1],
                'partial' => ['search_terms' => ['$type' => 'array']],
            ],
            'idx_account_profiles_contact_source_terms_v1' => [
                'key' => ['contact_mode' => 1, 'is_active' => 1, 'deleted_at' => 1, 'profile_type' => 1, 'search_terms' => 1, 'name_search_key' => 1, '_id' => 1],
                'partial' => ['search_terms' => ['$type' => 'array']],
            ],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }
        $database = DB::connection('tenant')->getDatabase();
        foreach (self::INDEXES as $collectionName => $indexes) {
            $collection = $database->selectCollection($collectionName);
            foreach ($indexes as $name => $definition) {
                $this->dropIfPresent($collection, $name);
                $collection->createIndex($definition['key'], [
                    'name' => $name,
                    'collation' => ['locale' => 'simple'],
                    'partialFilterExpression' => $definition['partial'],
                ]);
            }
        }
    }

    public function down(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        foreach (self::INDEXES as $collectionName => $indexes) {
            $collection = $database->selectCollection($collectionName);
            foreach (array_keys($indexes) as $name) {
                $this->dropIfPresent($collection, $name);
            }
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
