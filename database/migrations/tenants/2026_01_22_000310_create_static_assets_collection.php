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
        Schema::create('static_assets', function (Blueprint $collection) {
            $collection->index(['category' => 1, 'is_active' => 1]);
            $collection->index(['created_at' => -1, 'updated_at' => -1]);
            $collection->index(['deleted_at' => -1]);
            $collection->index(['location' => '2dsphere']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('static_assets');
    }
};
