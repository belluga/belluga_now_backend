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
        Schema::create('module_items', function (Blueprint $collection) {
            $collection->index('module_id');
            $collection->index('account_id');
            $collection->index('user_id');
            $collection->index('slug');
            $collection->index('data.title');
            $collection->timeSeries(0); // timestamps
        });
    }

    /**
     * Reverta as migrações.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_items');
    }
};
