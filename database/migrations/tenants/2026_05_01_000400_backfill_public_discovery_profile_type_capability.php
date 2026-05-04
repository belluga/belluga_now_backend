<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Schema\Blueprint;

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
            ['type' => 'personal'],
            [
                '$set' => [
                    'capabilities.is_publicly_discoverable' => false,
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

        Schema::table('account_profile_types', static function (Blueprint $collection): void {
            $collection->index(
                [
                    'capabilities.is_publicly_discoverable' => 1,
                    'capabilities.is_favoritable' => 1,
                    'capabilities.is_poi_enabled' => 1,
                ],
                options: ['name' => 'idx_account_profile_types_public_catalog_v1']
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            return;
        }

        Schema::table('account_profile_types', static function (Blueprint $collection): void {
            $collection->dropIndex('idx_account_profile_types_public_catalog_v1');
        });
    }
};
