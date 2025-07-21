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
        Schema::create('account_role_templates', function (Blueprint $collection) {
            $collection->index('permissions');
            $collection->unique(['account_id' => -1, 'slug' => -1]);
            $collection->index(['created_at' => -1, "updated_at" => -1], );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
