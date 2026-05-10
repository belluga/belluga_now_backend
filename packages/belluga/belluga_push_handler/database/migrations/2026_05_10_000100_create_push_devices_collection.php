<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $collection) {
            $collection->unique(
                ['account_user_id' => 1, 'device_id' => 1],
                options: ['name' => 'unique_push_device_per_user']
            );
            $collection->index(['account_user_id' => 1, 'is_active' => 1, '_id' => 1]);
            $collection->index(['account_ids' => 1, 'is_active' => 1, '_id' => 1]);
            $collection->index(['push_token' => 1]);
            $collection->index(['is_active' => 1, 'created_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
