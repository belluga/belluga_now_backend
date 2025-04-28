<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Execute as migrações.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $collection) {
            $collection->index('slug');
            $collection->index('name');
            $collection->index('created_by_id');
            $collection->index('created_by_type');
            $collection->index('show_in_menu');
            $collection->index('menu_position');
            $collection->timestamps(); // timestamps
        });
    }

    /**
     * Reverta as migrações.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
