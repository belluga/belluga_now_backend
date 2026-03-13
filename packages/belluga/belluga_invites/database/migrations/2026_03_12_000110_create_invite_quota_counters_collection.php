<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_quota_counters', function (Blueprint $collection): void {
            $collection->unique(
                ['scope' => 1, 'scope_id' => 1, 'window_key' => 1],
                options: ['name' => 'uq_invite_quota_counter_scope_window']
            );
            $collection->index(['window_key' => 1, 'updated_at' => -1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_quota_counters');
    }
};
