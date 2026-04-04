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
                'visibility' => 1,
                'is_active' => 1,
                'profile_type' => 1,
                'deleted_at' => 1,
                'created_at' => -1,
                '_id' => -1,
            ]);

            $collection->index(
                [
                    'visibility' => 1,
                    'is_active' => 1,
                    'profile_type' => 1,
                    'deleted_at' => 1,
                    'created_at' => -1,
                    '_id' => -1,
                ],
                options: [
                    'name' => 'idx_account_profiles_public_feed_v1',
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'visibility' => 1,
                'is_active' => 1,
                'profile_type' => 1,
                'deleted_at' => 1,
                'created_at' => -1,
                '_id' => -1,
            ]);
        });
    }
};
