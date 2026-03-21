<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'name' => 'text',
                'slug' => 'text',
                'document.number' => 'text',
            ]);

            $collection->index(
                [
                    'name' => 'text',
                    'slug' => 'text',
                    'document.number' => 'text',
                ],
                options: [
                    'name' => 'idx_accounts_text_search_v1',
                    'weights' => [
                        'name' => 10,
                        'slug' => 7,
                        'document.number' => 9,
                    ],
                    'default_language' => 'none',
                ]
            );
        });

        Schema::table('static_assets', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'display_name' => 'text',
                'slug' => 'text',
                'content' => 'text',
                'taxonomy_terms.value' => 'text',
            ]);

            $collection->index(
                [
                    'display_name' => 'text',
                    'slug' => 'text',
                    'content' => 'text',
                    'taxonomy_terms.value' => 'text',
                ],
                options: [
                    'name' => 'idx_static_assets_text_search_v1',
                    'weights' => [
                        'display_name' => 10,
                        'slug' => 8,
                        'content' => 4,
                        'taxonomy_terms.value' => 7,
                    ],
                    'default_language' => 'none',
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::table('accounts', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'name' => 'text',
                'slug' => 'text',
                'document.number' => 'text',
            ]);
        });

        Schema::table('static_assets', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'display_name' => 'text',
                'slug' => 'text',
                'content' => 'text',
                'taxonomy_terms.value' => 'text',
            ]);
        });
    }
};
