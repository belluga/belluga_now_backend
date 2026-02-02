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
        Schema::create('taxonomy_terms', function (Blueprint $collection) {
            $collection->index(['taxonomy_id' => 1]);
            $collection->unique(['taxonomy_id' => 1, 'slug' => 1]);
            $collection->index(['created_at' => -1, 'updated_at' => -1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
