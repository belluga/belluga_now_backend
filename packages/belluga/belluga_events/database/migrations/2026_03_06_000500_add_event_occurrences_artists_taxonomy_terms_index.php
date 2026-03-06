<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $collection): void {
            $collection->index([
                'artists.taxonomy_terms.type' => 1,
                'artists.taxonomy_terms.value' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $collection): void {
            $collection->dropIndex([
                'artists.taxonomy_terms.type' => 1,
                'artists.taxonomy_terms.value' => 1,
                'starts_at' => 1,
                '_id' => 1,
            ]);
        });
    }
};
