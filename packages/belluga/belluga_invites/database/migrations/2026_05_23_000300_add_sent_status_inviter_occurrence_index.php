<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
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
                    'name' => 'idx_invite_edges_sent_status_inviter_occurrence',
                ],
            );
        });
    }

    public function down(): void
    {
        // No-op for MongoDB index rollback in this migration slice.
    }
};
