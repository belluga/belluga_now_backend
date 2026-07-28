<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\CommandException;

return new class extends Migration
{
    private const OUTBOX_COLLECTION = 'account_profile_outbox';

    private const CHECKPOINTS_COLLECTION = 'account_profile_projection_checkpoints';

    private const DELETION_ATTEMPTS_COLLECTION = 'account_profile_deletion_attempts';

    public function up(): void
    {
        if (! Schema::hasCollection('account_profiles')) {
            return;
        }

        $database = DB::connection('tenant')->getDatabase();

        $outbox = $database->selectCollection(self::OUTBOX_COLLECTION);
        $this->dropIndexIfPresent($outbox, 'uniq_account_profile_outbox_command_v1');
        $this->dropIndexIfPresent($outbox, 'idx_account_profile_outbox_delivery_claim_v1');
        $this->dropIndexIfPresent($outbox, 'idx_account_profile_outbox_profile_tuple_v1');
        $outbox->createIndex(
            ['command_id' => 1],
            [
                'name' => 'uniq_account_profile_outbox_command_v1',
                'unique' => true,
                'partialFilterExpression' => ['command_id' => ['$exists' => true]],
            ],
        );
        $outbox->createIndex(
            ['delivery_state' => 1, 'claim_expires_at' => 1, 'occurred_at' => 1, '_id' => 1],
            ['name' => 'idx_account_profile_outbox_delivery_claim_v1'],
        );
        $outbox->createIndex(
            ['profile_id' => 1, 'aggregate_revision' => 1, 'operation_rank' => 1, '_id' => 1],
            ['name' => 'idx_account_profile_outbox_profile_tuple_v1'],
        );

        $checkpoints = $database->selectCollection(self::CHECKPOINTS_COLLECTION);
        $this->dropIndexIfPresent($checkpoints, 'idx_account_profile_projection_checkpoints_consumer_profile_v1');
        $checkpoints->createIndex(
            ['consumer_id' => 1, 'profile_id' => 1],
            ['name' => 'idx_account_profile_projection_checkpoints_consumer_profile_v1'],
        );

        $deletionAttempts = $database->selectCollection(self::DELETION_ATTEMPTS_COLLECTION);
        $this->dropIndexIfPresent($deletionAttempts, 'idx_account_profile_deletion_attempts_claim_v1');
        $deletionAttempts->createIndex(
            ['_id' => 1, 'phase' => 1, 'state_revision' => 1, 'claim_expires_at' => 1],
            ['name' => 'idx_account_profile_deletion_attempts_claim_v1'],
        );
    }

    public function down(): void
    {
        $database = DB::connection('tenant')->getDatabase();

        $this->dropIndexIfPresent(
            $database->selectCollection(self::OUTBOX_COLLECTION),
            'uniq_account_profile_outbox_command_v1',
        );
        $this->dropIndexIfPresent(
            $database->selectCollection(self::OUTBOX_COLLECTION),
            'idx_account_profile_outbox_delivery_claim_v1',
        );
        $this->dropIndexIfPresent(
            $database->selectCollection(self::OUTBOX_COLLECTION),
            'idx_account_profile_outbox_profile_tuple_v1',
        );
        $this->dropIndexIfPresent(
            $database->selectCollection(self::CHECKPOINTS_COLLECTION),
            'idx_account_profile_projection_checkpoints_consumer_profile_v1',
        );
        $this->dropIndexIfPresent(
            $database->selectCollection(self::DELETION_ATTEMPTS_COLLECTION),
            'idx_account_profile_deletion_attempts_claim_v1',
        );
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== $name) {
                continue;
            }

            try {
                $collection->dropIndex($name);
            } catch (CommandException $exception) {
                if ($exception->getCode() !== 27) {
                    throw $exception;
                }
            }

            return;
        }
    }
};
