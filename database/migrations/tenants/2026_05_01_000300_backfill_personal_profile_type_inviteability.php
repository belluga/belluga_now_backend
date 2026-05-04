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

        $existing = $collection->findOne(['type' => 'personal']);
        if ($existing === null) {
            $collection->insertOne([
                'type' => 'personal',
                'label' => 'Personal',
                'allowed_taxonomies' => [],
                'poi_visual' => null,
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_inviteable' => true,
                    'is_poi_enabled' => false,
                    'has_content' => false,
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $collection->updateOne(
            ['type' => 'personal'],
            [
                '$set' => [
                    'capabilities.is_favoritable' => true,
                    'capabilities.is_inviteable' => true,
                    'updated_at' => $now,
                ],
            ],
        );
    }

    public function down(): void
    {
        // No destructive rollback: personal inviteability is a release contract.
    }
};
