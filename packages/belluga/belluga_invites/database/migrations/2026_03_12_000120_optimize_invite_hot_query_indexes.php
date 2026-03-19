<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invite_share_codes', function (Blueprint $collection): void {
            $collection->index([
                'event_id' => 1,
                'occurrence_id' => 1,
                'inviter_principal.kind' => 1,
                'inviter_principal.principal_id' => 1,
                'created_at' => -1,
                '_id' => 1,
            ]);
        });

        Schema::table('invite_edges', function (Blueprint $collection): void {
            $collection->index([
                'receiver_user_id' => 1,
                'event_id' => 1,
                'occurrence_id' => 1,
                'credited_acceptance' => 1,
                '_id' => 1,
            ]);

            $collection->index([
                'receiver_user_id' => 1,
                'event_id' => 1,
                'occurrence_id' => 1,
                'status' => 1,
                'created_at' => 1,
                '_id' => 1,
            ]);
        });
    }

    public function down(): void
    {
        // No-op for MongoDB index rollback in this migration slice.
    }
};
