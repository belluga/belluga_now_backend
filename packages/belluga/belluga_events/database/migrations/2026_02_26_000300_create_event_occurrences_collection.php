<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $collection): void {
            $collection->unique(['event_id' => 1, 'occurrence_index' => 1]);
            $collection->index(['is_event_published' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['event_id' => 1, 'starts_at' => 1]);
            $collection->index(['updated_at' => 1, '_id' => 1]);
            $collection->index(['venue_geo' => '2dsphere']);
            $collection->index(['deleted_at' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
