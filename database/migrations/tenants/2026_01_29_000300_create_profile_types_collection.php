<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_profile_types', function (Blueprint $collection) {
            $collection->unique('type');
            $collection->index(['capabilities.is_favoritable' => 1]);
            $collection->index(['capabilities.is_poi_enabled' => 1]);
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
