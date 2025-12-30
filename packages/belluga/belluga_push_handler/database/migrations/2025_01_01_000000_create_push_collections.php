<?php

use Illuminate\Database\Migrations\Migration;
use MongoDB\Laravel\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_messages', function (Blueprint $collection) {
            $collection->index(['partner_id' => 1]);
            $collection->unique(['partner_id', 'internal_name']);
            $collection->index(['status' => 1]);
            $collection->index(['created_at' => -1]);
        });

        Schema::create('push_message_actions', function (Blueprint $collection) {
            $collection->index(['push_message_id' => 1]);
            $collection->index(['user_id' => 1]);
            $collection->unique('idempotency_key');
            $collection->index(['action' => 1]);
        });

        Schema::create('tenant_push_settings', function (Blueprint $collection) {
            $collection->index(['created_at' => -1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
        Schema::dropIfExists('push_message_actions');
        Schema::dropIfExists('tenant_push_settings');
    }
};
