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
        Schema::create('account_profiles', function (Blueprint $collection) {
            $collection->unique('account_id');
            $collection->unique('slug');
            $collection->index(['profile_type' => 1]);
            $collection->index(['location' => '2dsphere']);
            $collection->index(['created_at' => -1, 'updated_at' => -1]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_profiles');
    }
};
