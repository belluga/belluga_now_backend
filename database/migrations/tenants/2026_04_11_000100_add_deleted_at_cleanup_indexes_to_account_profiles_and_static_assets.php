<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'deleted_at' => 1,
                '_id' => 1,
            ]);

            $collection->index(
                [
                    'deleted_at' => 1,
                    '_id' => 1,
                ],
                options: [
                    'name' => 'idx_account_profiles_deleted_cleanup_v1',
                ]
            );
        });

        Schema::table('static_assets', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'deleted_at' => 1,
                '_id' => 1,
            ]);

            $collection->index(
                [
                    'deleted_at' => 1,
                    '_id' => 1,
                ],
                options: [
                    'name' => 'idx_static_assets_deleted_cleanup_v1',
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'deleted_at' => 1,
                '_id' => 1,
            ]);
        });

        Schema::table('static_assets', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'deleted_at' => 1,
                '_id' => 1,
            ]);
        });
    }
};
