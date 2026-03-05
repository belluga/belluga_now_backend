<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $collection) {
            $collection->unique('slug');
            $collection->index(['name' => 1]);
            $collection->index(['created_at' => -1, 'updated_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
