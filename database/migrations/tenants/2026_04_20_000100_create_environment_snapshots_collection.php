<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_snapshots', static function (Blueprint $collection): void {
            $collection->index(
                ['schema_version' => 1],
                options: ['name' => 'idx_environment_snapshots_schema_version_v1']
            );
            $collection->index(
                ['built_at' => -1],
                options: ['name' => 'idx_environment_snapshots_built_at_v1']
            );
            $collection->index(
                ['last_rebuild_failed_at' => -1],
                options: ['name' => 'idx_environment_snapshots_failed_at_v1']
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_snapshots');
    }
};
