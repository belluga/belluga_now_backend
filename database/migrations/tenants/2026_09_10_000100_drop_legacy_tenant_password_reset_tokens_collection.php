<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $landlord = DB::connection('landlord')->getDatabase()->selectCollection('password_reset_tokens');
        $required = [
            '_id_' => ['_id' => 1], 'broker_1' => ['broker' => 1], 'expires_at_1' => ['expires_at' => 1],
            'slot_key_1' => ['slot_key' => 1], 'token_lookup_hash_1' => ['token_lookup_hash' => 1],
            'user_id_1' => ['user_id' => 1], 'user_id_string_1' => ['user_id_string' => 1],
        ];
        $actual = [];
        foreach ($landlord->listIndexes() as $index) $actual[$index->getName()] = [
            'key' => json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            'options' => ['unique' => $index['unique'] ?? false],
        ];
        foreach ($required as $name => $key) if (($actual[$name]['key'] ?? null) !== $key || ($name === 'slot_key_1' && (($actual[$name]['options']['unique'] ?? false) !== true))) {
            throw new RuntimeException('tenant.password_reset_tokens.empty_v1 requires the canonical landlord owner.');
        }

        $tenantDatabase = DB::connection('tenant')->getDatabase();
        $collections = iterator_to_array($tenantDatabase->listCollections(['filter' => ['name' => 'password_reset_tokens']]));
        if ($collections !== [] && $tenantDatabase->selectCollection('password_reset_tokens')->countDocuments() !== 0) {
            throw new RuntimeException('tenant.password_reset_tokens.empty_v1 refuses to drop non-empty tenant tokens.');
        }

        if ($collections !== []) $tenantDatabase->dropCollection('password_reset_tokens');
    }

    public function down(): void {}
};
