<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorite_edges', function (Blueprint $collection): void {
            $collection->unique(
                [
                    'owner_user_id' => 1,
                    'registry_key' => 1,
                    'target_type' => 1,
                    'target_id' => 1,
                ],
                options: [
                    'name' => 'uq_favorite_edges_owner_registry_target',
                ]
            );

            $collection->index(['owner_user_id' => 1, 'favorited_at' => -1, '_id' => 1]);
            $collection->index(['registry_key' => 1, '_id' => 1]);
        });

        Schema::create('favoritable_snapshots', function (Blueprint $collection): void {
            $collection->unique([
                'registry_key' => 1,
                'target_type' => 1,
                'target_id' => 1,
            ]);
            $collection->index(['registry_key' => 1, 'updated_at' => -1, '_id' => 1]);
        });

        Schema::create('favoritable_account_profile_snapshots', function (Blueprint $collection): void {
            $collection->unique([
                'registry_key' => 1,
                'target_type' => 1,
                'target_id' => 1,
            ]);
            $collection->index(['next_event_occurrence_at' => 1, '_id' => 1]);
            $collection->index(['last_event_occurrence_at' => -1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favoritable_account_profile_snapshots');
        Schema::dropIfExists('favoritable_snapshots');
        Schema::dropIfExists('favorite_edges');
    }
};
