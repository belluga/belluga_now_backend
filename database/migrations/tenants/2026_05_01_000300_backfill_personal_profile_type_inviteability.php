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

        $collection->updateOne(
            ['type' => 'personal'],
            [
                '$set' => [
                    'label' => 'Personal',
                    'allowed_taxonomies' => [],
                    'poi_visual' => null,
                    'capabilities.is_favoritable' => true,
                    'capabilities.is_inviteable' => true,
                    'capabilities.is_poi_enabled' => false,
                    'capabilities.has_content' => false,
                    'updated_at' => $now,
                ],
                '$setOnInsert' => [
                    'type' => 'personal',
                    'created_at' => $now,
                ],
            ],
            ['upsert' => true],
        );
    }

    public function down(): void
    {
        // No destructive rollback: personal inviteability is a release contract.
    }
};
