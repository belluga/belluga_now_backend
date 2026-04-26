<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proximity_preferences', static function (Blueprint $collection): void {
            $collection->unique(
                ['owner_user_id' => 1],
                options: ['name' => 'uq_proximity_preferences_owner_user_id_v1'],
            );

            $collection->index(
                ['updated_at' => -1, '_id' => 1],
                options: ['name' => 'idx_proximity_preferences_updated_at_v1'],
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proximity_preferences');
    }
};
