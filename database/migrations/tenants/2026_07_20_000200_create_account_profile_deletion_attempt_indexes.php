<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_deletion_attempts')
            ->createIndex(
                ['phase' => 1, 'claim_expires_at' => 1],
                ['name' => 'idx_account_profile_deletion_attempts_claim_v1'],
            );
    }

    public function down(): void
    {
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_deletion_attempts');
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === 'idx_account_profile_deletion_attempts_claim_v1') {
                $collection->dropIndex('idx_account_profile_deletion_attempts_claim_v1');

                return;
            }
        }
    }
};
