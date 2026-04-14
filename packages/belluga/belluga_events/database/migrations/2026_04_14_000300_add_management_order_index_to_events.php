<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', static function (Blueprint $collection): void {
            try {
                $collection->dropIndex('idx_events_management_order_v1');
            } catch (\Throwable) {
                // no-op
            }

            $collection->index(
                [
                    'date_time_start' => 1,
                    '_id' => -1,
                ],
                options: [
                    'name' => 'idx_events_management_order_v1',
                ]
            );
        });
    }

    public function down(): void
    {
        Schema::table('events', static function (Blueprint $collection): void {
            try {
                $collection->dropIndex('idx_events_management_order_v1');
            } catch (\Throwable) {
                // no-op
            }
        });
    }
};
