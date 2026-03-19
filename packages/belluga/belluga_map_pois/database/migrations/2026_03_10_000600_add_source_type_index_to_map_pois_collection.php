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
        Schema::table('map_pois', function (Blueprint $collection) {
            $collection->index(['ref_type' => 1, 'source_type' => 1, 'updated_at' => -1, '_id' => 1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for MongoDB index rollback in this migration slice.
    }
};
