<?php

declare(strict_types=1);

use App\Models\Tenants\PhoneOtpChallenge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\ObjectId;

return new class extends Migration
{
    private const string INDEX_NAME = 'uq_phone_otp_challenges_pending_phone_v1';

    public function up(): void
    {
        if (! Schema::hasTable('phone_otp_challenges')) {
            return;
        }

        $collection = DB::connection('tenant')->getCollection('phone_otp_challenges');

        $duplicates = $collection->aggregate([
            ['$match' => ['status' => PhoneOtpChallenge::STATUS_PENDING]],
            ['$sort' => ['created_at' => -1, '_id' => -1]],
            [
                '$group' => [
                    '_id' => '$phone',
                    'ids' => ['$push' => '$_id'],
                    'count' => ['$sum' => 1],
                ],
            ],
            ['$match' => ['count' => ['$gt' => 1]]],
        ]);

        foreach ($duplicates as $duplicate) {
            $ids = is_array($duplicate['ids'] ?? null) ? $duplicate['ids'] : iterator_to_array($duplicate['ids'] ?? []);
            $staleIds = array_slice($ids, 1);
            $objectIds = array_values(array_filter($staleIds, static fn (mixed $id): bool => $id instanceof ObjectId));

            if ($objectIds === []) {
                continue;
            }

            $collection->updateMany(
                ['_id' => ['$in' => $objectIds]],
                ['$set' => ['status' => PhoneOtpChallenge::STATUS_SUPERSEDED]],
            );
        }

        try {
            $collection->dropIndex(self::INDEX_NAME);
        } catch (\Throwable) {
            // Index may not exist yet on local or partially migrated databases.
        }

        $collection->createIndex(
            ['phone' => 1, 'status' => 1],
            [
                'name' => self::INDEX_NAME,
                'unique' => true,
                'partialFilterExpression' => [
                    'status' => PhoneOtpChallenge::STATUS_PENDING,
                ],
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('phone_otp_challenges')) {
            return;
        }

        try {
            DB::connection('tenant')->getCollection('phone_otp_challenges')->dropIndex(self::INDEX_NAME);
        } catch (\Throwable) {
            // Index may not exist on partially migrated local/test databases.
        }
    }
};
