<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    private const string INDEX_NAME = 'idx_invite_edges_sent_status_inviter_occurrence';

    public function up(): void
    {
        if (! Schema::hasTable('invite_edges')) {
            return;
        }

        $this->dropIndexIfExists();

        Schema::table('invite_edges', function (Blueprint $collection): void {
            $collection->index(
                [
                    'issued_by_user_id' => 1,
                    'event_id' => 1,
                    'occurrence_id' => 1,
                    'inviter_principal.kind' => 1,
                    'inviter_principal.principal_id' => 1,
                    'created_at' => -1,
                    '_id' => -1,
                ],
                options: [
                    'name' => self::INDEX_NAME,
                ],
            );
        });
    }

    public function down(): void
    {
        // No-op for MongoDB index rollback in this migration slice.
    }

    private function dropIndexIfExists(): void
    {
        try {
            DB::connection('tenant')
                ->getCollection('invite_edges')
                ->dropIndex(self::INDEX_NAME);
        } catch (Throwable) {
            // Fresh databases and pre-rebuild tenants may not have this index yet.
        }
    }
};
