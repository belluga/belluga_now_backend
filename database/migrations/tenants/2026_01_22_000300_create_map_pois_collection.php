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
        Schema::create('map_pois', function (Blueprint $collection) {
            $collection->unique(['ref_type' => 1, 'ref_id' => 1]);
            $collection->index(['exact_key' => 1]);
            $collection->index(['category' => 1, 'is_active' => 1]);
            $collection->index(['time_anchor_at' => 1]);
            $collection->index(['updated_at' => -1]);
            $collection->index(['deleted_at' => -1]);
            $collection->index(['location' => '2dsphere']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('map_pois');
    }
};
