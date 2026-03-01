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
            $collection->unique(['projection_key' => 1]);
            $collection->index(['location' => '2dsphere']);
            $collection->index(['is_active' => 1, 'updated_at' => -1, '_id' => 1]);
            $collection->index(['active_window_start_at' => 1, 'active_window_end_at' => 1, '_id' => 1]);
            $collection->index(['category' => 1, 'updated_at' => -1, '_id' => 1]);
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
