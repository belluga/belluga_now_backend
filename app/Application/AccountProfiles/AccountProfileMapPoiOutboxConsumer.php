<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Belluga\MapPois\Application\MapPoiProjectionService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;

final class AccountProfileMapPoiOutboxConsumer implements AccountProfileOutboxConsumer
{
    private const CONSUMER_ID = 'map_poi';

    public function __construct(
        private readonly AccountProfileProjectionCheckpointStore $checkpoints,
        private readonly MapPoiProjectionService $mapPois,
    ) {}

    public function consumerId(): string
    {
        return self::CONSUMER_ID;
    }

    /** @param array<string, mixed> $event */
    public function consume(AccountProfileTransactionContext $context, array $event): void
    {
        if ($this->checkpoints->isAtOrAhead($context, $this->consumerId(), $event)) {
            return;
        }

        $profileId = trim((string) ($event['profile_id'] ?? ''));
        if ($profileId === '') {
            throw new RuntimeException('Account Profile Map POI outbox event requires a profile id.');
        }

        if ((string) ($event['operation'] ?? '') !== 'tombstone') {
            $projection = $event['projection'] ?? null;
            if (! is_array($projection)) {
                throw new RuntimeException('Account Profile Map POI upsert event requires an immutable projection.');
            }
            $projection['_id'] = $profileId;

            try {
                $profileObjectId = new ObjectId($profileId);
            } catch (\Throwable) {
                throw new RuntimeException('Account Profile Map POI upsert event has an invalid profile id.');
            }
            $touched = $context->collection('account_profiles')->updateOne(
                ['_id' => $profileObjectId, 'deleted_at' => null],
                ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
                $context->rawOptions(),
            );
            if ($touched->getMatchedCount() !== 1) {
                $this->checkpoints->advance($context, $this->consumerId(), $event);

                return;
            }

            $this->mapPois->upsertFromAccountProfileWithinTransaction(
                (object) $projection,
                $context->database(),
                $context->session(),
                (int) ($projection['source_checkpoint'] ?? 0),
            );
        }

        // The projection effect and this monotonic tuple commit together.
        $this->checkpoints->advance($context, $this->consumerId(), $event);
    }
}
