<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $database = DB::connection('tenant')->getDatabase();
        $database->selectCollection('account_profiles')->updateMany(
            [
                '$or' => [
                    ['aggregate_revision' => ['$exists' => false]],
                    ['aggregate_revision' => null],
                ],
            ],
            ['$set' => ['aggregate_revision' => 0]],
        );

        $outbox = $database->selectCollection('account_profile_outbox');
        $outbox->updateMany(
            ['delivery_state' => ['$exists' => false]],
            ['$set' => [
                'delivery_state' => 'pending',
                'delivery_attempts' => 0,
            ]],
        );
        $outbox->createIndex(
            ['command_id' => 1],
            ['name' => 'uniq_account_profile_outbox_command_v1', 'unique' => true],
        );
        $outbox->createIndex(
            ['delivery_state' => 1, 'claim_expires_at' => 1, 'occurred_at' => 1],
            ['name' => 'idx_account_profile_outbox_delivery_claim_v1'],
        );
        $outbox->createIndex(
            ['profile_id' => 1, 'aggregate_revision' => 1, 'operation_rank' => 1],
            ['name' => 'idx_account_profile_outbox_profile_tuple_v1'],
        );

        $database->selectCollection('account_profile_projection_checkpoints')->createIndex(
            ['consumer_id' => 1, 'profile_id' => 1],
            ['name' => 'idx_account_profile_projection_checkpoints_consumer_profile_v1', 'unique' => true],
        );
    }

    public function down(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $this->dropIndexIfPresent($database->selectCollection('account_profile_outbox'), 'uniq_account_profile_outbox_command_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profile_outbox'), 'idx_account_profile_outbox_delivery_claim_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profile_outbox'), 'idx_account_profile_outbox_profile_tuple_v1');
        $this->dropIndexIfPresent($database->selectCollection('account_profile_projection_checkpoints'), 'idx_account_profile_projection_checkpoints_consumer_profile_v1');
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                $collection->dropIndex($name);

                return;
            }
        }
    }
};
