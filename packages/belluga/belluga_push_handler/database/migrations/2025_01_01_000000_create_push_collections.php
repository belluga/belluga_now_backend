<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_messages', function (Blueprint $collection) {
            $collection->index(['scope' => 1]);
            $collection->index(['partner_id' => 1]);
            $collection->unique(
                ['partner_id', 'internal_name'],
                options: [
                    'name' => 'unique_account_internal_name',
                    'partialFilterExpression' => [
                        'partner_id' => ['$exists' => true],
                    ],
                ]
            );
            $collection->unique(
                ['scope', 'internal_name'],
                options: [
                    'name' => 'unique_tenant_internal_name',
                    'partialFilterExpression' => [
                        'scope' => 'tenant',
                    ],
                ]
            );
            $collection->index(['status' => 1]);
            $collection->index(['created_at' => -1]);
        });

        Schema::create('push_message_actions', function (Blueprint $collection) {
            $collection->index(['push_message_id' => 1]);
            $collection->index(['user_id' => 1]);
            $collection->unique('idempotency_key');
            $collection->index(['action' => 1]);
        });

        Schema::create('push_credentials', function (Blueprint $collection) {
            $collection->index(['created_at' => -1]);
        });

        Schema::create('push_delivery_logs', function (Blueprint $collection) {
            $collection->index(['push_message_id' => 1]);
            $collection->index(['message_instance_id' => 1]);
            $collection->index(['status' => 1]);
            $collection->index(['created_at' => -1]);
            $collection->index(['token_hash' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
        Schema::dropIfExists('push_message_actions');
        Schema::dropIfExists('push_credentials');
        Schema::dropIfExists('push_delivery_logs');
    }
};
