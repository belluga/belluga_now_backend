<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\UTCDateTime;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('account_profile_types');
        $now = new UTCDateTime((int) Carbon::now()->getTimestampMs());

        $collection->updateMany(
            [
                'type' => 'personal',
                '$or' => [
                    ['capabilities.is_queryable' => ['$exists' => false]],
                    ['capabilities.is_queryable' => null],
                    ['capabilities.is_publicly_discoverable' => ['$exists' => false]],
                    ['capabilities.is_publicly_discoverable' => null],
                    ['capabilities.is_publicly_navigable' => ['$exists' => false]],
                    ['capabilities.is_publicly_navigable' => null],
                ],
            ],
            [
                '$set' => [
                    'capabilities.is_queryable' => false,
                    'capabilities.is_publicly_discoverable' => false,
                    'capabilities.is_publicly_navigable' => false,
                    'updated_at' => $now,
                ],
            ],
        );

        $collection->updateMany(
            [
                'type' => ['$ne' => 'personal'],
                '$or' => [
                    ['capabilities.is_queryable' => ['$exists' => false]],
                    ['capabilities.is_queryable' => null],
                ],
            ],
            [
                '$set' => [
                    'capabilities.is_queryable' => true,
                    'updated_at' => $now,
                ],
            ],
        );

        $collection->updateMany(
            [
                'type' => ['$ne' => 'personal'],
                '$or' => [
                    ['capabilities.is_publicly_discoverable' => ['$exists' => false]],
                    ['capabilities.is_publicly_discoverable' => null],
                ],
            ],
            [
                '$set' => [
                    'capabilities.is_publicly_discoverable' => true,
                    'updated_at' => $now,
                ],
            ],
        );

        $collection->updateMany(
            [
                'type' => ['$ne' => 'personal'],
                '$or' => [
                    ['capabilities.is_publicly_navigable' => ['$exists' => false]],
                    ['capabilities.is_publicly_navigable' => null],
                ],
            ],
            [
                '$set' => [
                    'capabilities.is_publicly_navigable' => true,
                    'updated_at' => $now,
                ],
            ],
        );
    }

    public function down(): void
    {
        // This backfill is intentionally irreversible because it repairs legacy null/missing capability state.
    }
};
