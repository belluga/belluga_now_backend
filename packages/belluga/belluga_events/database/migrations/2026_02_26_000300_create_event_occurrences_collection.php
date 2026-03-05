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
            $collection->unique(['occurrence_slug' => 1]);
            $collection->index(['deleted_at' => 1, 'is_event_published' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['event_id' => 1, 'starts_at' => 1]);
            $collection->index(['event_id' => 1, 'occurrence_slug' => 1, '_id' => 1]);
            $collection->index(['updated_at' => 1, '_id' => 1]);
            $collection->index(['geo_location' => '2dsphere']);
            $collection->index(['deleted_at' => 1, '_id' => 1]);
            $collection->index(['place_ref.type' => 1, 'place_ref.id' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['location.mode' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['categories' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['tags' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['taxonomy_terms.type' => 1, 'taxonomy_terms.value' => 1, 'starts_at' => 1, '_id' => 1]);
            $collection->index(['venue.taxonomy_terms.type' => 1, 'venue.taxonomy_terms.value' => 1, 'starts_at' => 1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
