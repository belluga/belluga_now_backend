<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $existing = null;
        foreach ($database->listCollections(['filter' => ['name' => 'account_profile_command_receipts']]) as $collection) {
            $existing = $collection;
        }
        if ($existing === null) {
            $database->createCollection('account_profile_command_receipts');

            return;
        }
        $options = json_decode(json_encode($existing->getOptions(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if ($existing->getType() !== 'collection' || $options !== []) {
            throw new RuntimeException('tenant.account_profile_command_receipts.compatible_v1 found incompatible collection options.');
        }
        $receipts = $database->selectCollection('account_profile_command_receipts');
        $indexes = iterator_to_array($receipts->listIndexes());
        if (count($indexes) !== 1
            || $indexes[0]->getName() !== '_id_'
            || json_decode(json_encode($indexes[0]->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR) !== ['_id' => 1]
            || (int) ($indexes[0]['v'] ?? 0) !== 2) {
            throw new RuntimeException('tenant.account_profile_command_receipts.compatible_v1 found incompatible index.');
        }
        foreach (['unique', 'sparse', 'partialFilterExpression', 'collation', 'expireAfterSeconds', 'hidden'] as $option) {
            if (isset($indexes[0][$option])) {
                throw new RuntimeException('tenant.account_profile_command_receipts.compatible_v1 found incompatible index.');
            }
        }
    }

    public function down(): void {}
};
