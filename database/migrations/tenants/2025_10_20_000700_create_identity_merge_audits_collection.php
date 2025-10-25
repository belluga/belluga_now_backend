<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('identity_merge_audits', function (Blueprint $collection) {
            $collection->options(['capped' => true, 'size' => 16777216]);
            $collection->index(
                ['tenant_id' => 1, 'canonical_user_id' => 1, 'consolidated_at' => -1],
                options: ['name' => 'canonical_identity_merge_audits']
            );

            $collection->index(
                ['tenant_id' => 1, 'merged_source_ids' => 1],
                options: ['name' => 'merged_source_lookup']
            );

            $collection->index(
                ['tenant_id' => 1, 'sources.source_user_id' => 1],
                options: ['name' => 'source_user_lookup']
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_merge_audits');
    }
};

