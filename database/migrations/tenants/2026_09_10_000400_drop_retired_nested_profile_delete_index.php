<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $collections = [];
        foreach ($database->listCollections() as $collection) {
            $collections[$collection->getName()] = true;
        }
        if (! isset($collections['accounts_nested'], $collections['account_profiles'])) {
            throw new RuntimeException('tenant.account_profiles.nested_delete_retirement_v1 requires accounts_nested.');
        }
        $owner = $database->selectCollection('accounts_nested');
        $ownerIndex = null;
        foreach ($owner->listIndexes() as $index) {
            if ($index->getName() === 'idx_accounts_nested_member_lookup_v1') {
                $ownerIndex = $index;
            }
        }
        if ($ownerIndex === null
            || self::indexKey($ownerIndex) !== ['tenant_id' => 1, 'nested_profile.id' => 1, 'doc_type' => 1, '_id' => 1]
            || self::indexOptions($ownerIndex) !== ['v' => 2, 'partialFilterExpression' => ['doc_type' => 'member_row']]) {
            throw new RuntimeException('tenant.account_profiles.nested_delete_retirement_v1 requires canonical accounts_nested owner.');
        }
        $profiles = $database->selectCollection('account_profiles');
        $found = null;
        foreach ($profiles->listIndexes() as $index) {
            if ($index->getName() === 'idx_account_profiles_nested_member_delete_v1') {
                $found = $index;
            }
        }
        if ($found === null) {
            return;
        }
        if (self::indexKey($found) !== ['nested_profile_groups.account_profile_ids' => 1, '_id' => 1]
            || self::indexOptions($found) !== ['v' => 2]) {
            throw new RuntimeException('tenant.account_profiles.nested_delete_retirement_v1 found incompatible retired index.');
        }
        $profiles->dropIndex('idx_account_profiles_nested_member_delete_v1');
    }

    public function down(): void {}

    /** @return array<string, int> */
    private static function indexKey(object $index): array
    {
        return json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function indexOptions(object $index): array
    {
        $options = [];
        foreach (['v', 'unique', 'partialFilterExpression', 'collation', 'expireAfterSeconds', 'sparse', 'hidden'] as $option) {
            if (isset($index[$option])) {
                $options[$option] = $index[$option];
            }
        }

        return $options;
    }
};
