<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_edges', function (Blueprint $collection): void {
            $collection->index(['receiver_user_id' => 1, 'status' => 1, 'event_date' => 1, '_id' => 1]);
            $collection->index(['receiver_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'status' => 1, '_id' => 1]);
            $collection->index(['inviter_principal.kind' => 1, 'inviter_principal.principal_id' => 1, 'created_at' => -1, '_id' => 1]);
            $collection->index(['issued_by_user_id' => 1, 'created_at' => -1, '_id' => 1]);
            $collection->unique(
                [
                    'event_id' => 1,
                    'occurrence_id' => 1,
                    'receiver_account_profile_id' => 1,
                    'inviter_principal.kind' => 1,
                    'inviter_principal.principal_id' => 1,
                ],
                options: [
                    'name' => 'uq_invite_edges_target_receiver_principal',
                    'partialFilterExpression' => [
                        'receiver_account_profile_id' => ['$exists' => true],
                    ],
                ]
            );
        });

        Schema::create('invite_feed_projection', function (Blueprint $collection): void {
            $collection->unique(['receiver_user_id' => 1, 'group_key' => 1]);
            $collection->index(['receiver_user_id' => 1, 'event_date' => 1, '_id' => 1]);
            $collection->index(['event_id' => 1, 'occurrence_id' => 1, '_id' => 1]);
        });

        Schema::create('invite_outbox_events', function (Blueprint $collection): void {
            $collection->index(['receiver_user_id' => 1, 'available_at' => 1, '_id' => 1]);
            $collection->index(['status' => 1, 'available_at' => 1, '_id' => 1]);
            $collection->unique('dedupe_key');
        });

        Schema::create('contact_hash_directory', function (Blueprint $collection): void {
            $collection->unique(['importing_user_id' => 1, 'contact_hash' => 1]);
            $collection->index(['importing_user_id' => 1, 'matched_user_id' => 1, '_id' => 1]);
            $collection->index(['contact_hash' => 1, 'type' => 1, '_id' => 1]);
        });

        Schema::create('invite_share_codes', function (Blueprint $collection): void {
            $collection->unique('code');
            $collection->index(['event_id' => 1, 'occurrence_id' => 1, '_id' => 1]);
            $collection->index(['inviter_principal.kind' => 1, 'inviter_principal.principal_id' => 1, '_id' => 1]);
            $collection->index(['issued_by_user_id' => 1, 'created_at' => -1, '_id' => 1]);
            $collection->index(['issued_by_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'created_at' => -1, '_id' => 1]);
        });

        Schema::create('principal_social_metrics', function (Blueprint $collection): void {
            $collection->unique(['principal_kind' => 1, 'principal_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principal_social_metrics');
        Schema::dropIfExists('invite_share_codes');
        Schema::dropIfExists('contact_hash_directory');
        Schema::dropIfExists('invite_outbox_events');
        Schema::dropIfExists('invite_feed_projection');
        Schema::dropIfExists('invite_edges');
    }
};
