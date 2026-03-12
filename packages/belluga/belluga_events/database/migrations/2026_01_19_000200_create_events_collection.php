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
        Schema::create('events', function (Blueprint $collection) {
            $collection->unique('slug');
            $collection->index(['date_time_start' => 1]);
            $collection->index(['updated_at' => -1]);
            $collection->index(['geo_location' => '2dsphere']);
            $collection->index(['publication.status' => 1, 'publication.publish_at' => 1, '_id' => 1]);
            $collection->index(['publication.status' => 1, 'date_time_start' => -1, '_id' => 1]);
            $collection->index(['place_ref.type' => 1, 'place_ref.id' => 1, 'date_time_start' => -1, '_id' => 1]);
            $collection->index(['location.mode' => 1, 'date_time_start' => -1, '_id' => 1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
