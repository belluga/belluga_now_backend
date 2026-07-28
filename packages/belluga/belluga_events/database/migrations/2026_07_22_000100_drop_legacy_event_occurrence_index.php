<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLLECTION = 'event_occurrences';

    private const LEGACY_UNIQUE_INDEX = 'event_id_1_occurrence_index_1';

    public function up(): void
    {
        $this->dropLegacyIndexIfPresent();
    }

    public function down(): void
    {
        // Legacy occurrence_index authority must not be restored automatically.
    }

    private function dropLegacyIndexIfPresent(): void
    {
        try {
            DB::connection('tenant')
                ->getCollection(self::COLLECTION)
                ->dropIndex(self::LEGACY_UNIQUE_INDEX);
        } catch (\Throwable) {
            // Index may already be absent on canonicalized tenants.
        }
    }
};
