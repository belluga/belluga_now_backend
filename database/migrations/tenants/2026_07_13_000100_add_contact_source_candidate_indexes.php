<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_profile_types', static function (Blueprint $collection): void {
            $collection->index(
                [
                    'capabilities.has_contact_channels' => 1,
                    'type' => 1,
                ],
                options: ['name' => 'idx_account_profile_types_contact_channels_v1'],
            );
        });

        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->index(
                [
                    'contact_mode' => 1,
                    'is_active' => 1,
                    'deleted_at' => 1,
                    'profile_type' => 1,
                    'display_name' => 1,
                    '_id' => 1,
                ],
                options: ['name' => 'idx_account_profiles_contact_source_candidates_v1'],
            );
        });
    }

    public function down(): void
    {
        Schema::table('account_profiles', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'contact_mode' => 1,
                'is_active' => 1,
                'deleted_at' => 1,
                'profile_type' => 1,
                'display_name' => 1,
                '_id' => 1,
            ]);
        });

        Schema::table('account_profile_types', static function (Blueprint $collection): void {
            $collection->dropIndexIfExists([
                'capabilities.has_contact_channels' => 1,
                'type' => 1,
            ]);
        });
    }
};
