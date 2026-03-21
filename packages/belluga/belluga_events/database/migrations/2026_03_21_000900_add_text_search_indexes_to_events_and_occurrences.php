<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'title' => 'text',
                'slug' => 'text',
                'content' => 'text',
                'tags' => 'text',
                'categories' => 'text',
                'taxonomy_terms.value' => 'text',
                'artists.display_name' => 'text',
            ]);

            $collection->index(
                [
                    'title' => 'text',
                    'slug' => 'text',
                    'content' => 'text',
                    'tags' => 'text',
                    'categories' => 'text',
                    'taxonomy_terms.value' => 'text',
                    'artists.display_name' => 'text',
                ],
                options: [
                    'name' => 'idx_events_text_search_v1',
                    'weights' => [
                        'title' => 10,
                        'slug' => 8,
                        'content' => 5,
                        'taxonomy_terms.value' => 7,
                        'artists.display_name' => 7,
                        'tags' => 4,
                        'categories' => 4,
                    ],
                    'default_language' => 'none',
                ]
            );
        });

        Schema::table('event_occurrences', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'title' => 'text',
                'slug' => 'text',
                'occurrence_slug' => 'text',
                'content' => 'text',
                'tags' => 'text',
                'categories' => 'text',
                'taxonomy_terms.value' => 'text',
                'artists.display_name' => 'text',
                'venue.display_name' => 'text',
            ]);

            $collection->index(
                [
                    'title' => 'text',
                    'slug' => 'text',
                    'occurrence_slug' => 'text',
                    'content' => 'text',
                    'tags' => 'text',
                    'categories' => 'text',
                    'taxonomy_terms.value' => 'text',
                    'artists.display_name' => 'text',
                    'venue.display_name' => 'text',
                ],
                options: [
                    'name' => 'idx_event_occurrences_text_search_v1',
                    'weights' => [
                        'title' => 10,
                        'slug' => 8,
                        'occurrence_slug' => 8,
                        'content' => 5,
                        'taxonomy_terms.value' => 7,
                        'artists.display_name' => 7,
                        'venue.display_name' => 7,
                        'tags' => 4,
                        'categories' => 4,
                    ],
                    'default_language' => 'none',
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'title' => 'text',
                'slug' => 'text',
                'content' => 'text',
                'tags' => 'text',
                'categories' => 'text',
                'taxonomy_terms.value' => 'text',
                'artists.display_name' => 'text',
            ]);
        });

        Schema::table('event_occurrences', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'title' => 'text',
                'slug' => 'text',
                'occurrence_slug' => 'text',
                'content' => 'text',
                'tags' => 'text',
                'categories' => 'text',
                'taxonomy_terms.value' => 'text',
                'artists.display_name' => 'text',
                'venue.display_name' => 'text',
            ]);
        });
    }
};
