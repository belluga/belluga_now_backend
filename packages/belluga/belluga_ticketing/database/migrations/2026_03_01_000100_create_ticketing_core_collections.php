<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_products', function (Blueprint $collection): void {
            $collection->index(['event_id' => 1, '_id' => 1]);
            $collection->index(['occurrence_id' => 1, '_id' => 1]);
            $collection->index(['scope_type' => 1, 'event_id' => 1, '_id' => 1]);
            $collection->unique(['event_id' => 1, 'slug' => 1]);
            $collection->index(['status' => 1, '_id' => 1]);
            $collection->index(['created_at' => -1]);
        });

        Schema::create('ticket_event_templates', function (Blueprint $collection): void {
            $collection->unique(['template_key' => 1, 'version' => 1]);
            $collection->index(['status' => 1, 'template_key' => 1, 'version' => -1, '_id' => 1]);
            $collection->index(['created_at' => -1]);
        });

        Schema::create('ticket_inventory_states', function (Blueprint $collection): void {
            $collection->unique(['occurrence_id' => 1, 'ticket_product_id' => 1]);
            $collection->index(['occurrence_id' => 1, '_id' => 1]);
        });

        Schema::create('ticket_holds', function (Blueprint $collection): void {
            $collection->index(['scope_type' => 1, 'scope_id' => 1, 'status' => 1, '_id' => 1]);
            $collection->index(['occurrence_id' => 1, 'status' => 1, 'expires_at' => 1, '_id' => 1]);
            $collection->index(['principal_id' => 1, 'status' => 1, 'expires_at' => 1, '_id' => 1]);
            $collection->unique('idempotency_key');
            $collection->unique('hold_token');
            $collection->index(['purge_at' => 1], options: ['expireAfterSeconds' => 0]);
        });

        Schema::create('ticket_queue_entries', function (Blueprint $collection): void {
            $collection->index(['scope_type' => 1, 'scope_id' => 1, 'status' => 1, 'position' => 1, '_id' => 1]);
            $collection->index(['queue_token' => 1, '_id' => 1]);
            $collection->unique('queue_token');
            $collection->unique(
                ['scope_type' => 1, 'scope_id' => 1, 'principal_id' => 1, 'status' => 1],
                options: [
                    'name' => 'uq_ticket_queue_active_principal_scope',
                    'partialFilterExpression' => ['status' => 'active'],
                ]
            );
            $collection->index(['purge_at' => 1], options: ['expireAfterSeconds' => 0]);
        });

        Schema::create('ticket_orders', function (Blueprint $collection): void {
            $collection->index(['occurrence_id' => 1, 'status' => 1, 'created_at' => 1, '_id' => 1]);
            $collection->index(['account_id' => 1, 'created_at' => 1, '_id' => 1]);
            $collection->unique('idempotency_key');
        });

        Schema::create('ticket_order_items', function (Blueprint $collection): void {
            $collection->index(['order_id' => 1, 'status' => 1, '_id' => 1]);
            $collection->index(['ticket_product_id' => 1, 'created_at' => 1, '_id' => 1]);
        });

        Schema::create('ticket_units', function (Blueprint $collection): void {
            $collection->index(['occurrence_id' => 1, 'lifecycle_state' => 1, '_id' => 1]);
            $collection->index(['principal_id' => 1, 'lifecycle_state' => 1, '_id' => 1]);
            $collection->index(['order_id' => 1, '_id' => 1]);
            $collection->index(['order_item_id' => 1, '_id' => 1]);
            $collection->unique(
                ['admission_code_hash' => 1],
                options: [
                    'name' => 'uq_ticket_units_admission_code_hash',
                    'partialFilterExpression' => [
                        'admission_code_hash' => ['$exists' => true],
                    ],
                ]
            );
        });

        Schema::create('ticket_checkin_logs', function (Blueprint $collection): void {
            $collection->index(['occurrence_id' => 1, 'checkpoint_ref' => 1, 'created_at' => 1, '_id' => 1]);
            $collection->unique('idempotency_key');
        });

        Schema::create('ticket_outbox_events', function (Blueprint $collection): void {
            $collection->index(['status' => 1, 'available_at' => 1, '_id' => 1]);
            $collection->unique('dedupe_key');
            $collection->index(['created_at' => 1, '_id' => 1]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_outbox_events');
        Schema::dropIfExists('ticket_checkin_logs');
        Schema::dropIfExists('ticket_units');
        Schema::dropIfExists('ticket_order_items');
        Schema::dropIfExists('ticket_orders');
        Schema::dropIfExists('ticket_queue_entries');
        Schema::dropIfExists('ticket_holds');
        Schema::dropIfExists('ticket_inventory_states');
        Schema::dropIfExists('ticket_event_templates');
        Schema::dropIfExists('ticket_products');
    }
};
