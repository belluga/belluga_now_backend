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
        Schema::create('account_profile_types', function (Blueprint $collection) {
            $collection->unique('type');
            $collection->index(
                ['capabilities.is_favoritable' => 1],
                'idx_account_profile_types_capability_is_favoritable_v1',
            );
            $collection->index(
                ['capabilities.is_poi_enabled' => 1],
                'idx_account_profile_types_capability_is_poi_enabled_v1',
            );
            $collection->index(['created_at' => -1, 'updated_at' => -1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_profile_types');
    }
};
