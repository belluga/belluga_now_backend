<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;
use RuntimeException;

final class AccountProfileProjectionCheckpointStore
{
    private const COLLECTION = 'account_profile_projection_checkpoints';

    /** @param array<string, mixed> $event */
    public function isAtOrAhead(
        AccountProfileTransactionContext $context,
        string $consumerId,
        array $event,
    ): bool {
        $checkpoint = $context->collection(self::COLLECTION)->findOne(
            ['_id' => $this->checkpointId($consumerId, $event)],
            $context->rawOptions(),
        );
        $checkpoint = $this->documentToArray($checkpoint);
        if ($checkpoint === null) {
            return false;
        }

        $storedRevision = (int) ($checkpoint['aggregate_revision'] ?? 0);
        $storedRank = (int) ($checkpoint['operation_rank'] ?? 0);
        $incomingRevision = $this->aggregateRevision($event);
        $incomingRank = $this->operationRank($event);

        return $storedRevision > $incomingRevision
            || ($storedRevision === $incomingRevision && $storedRank >= $incomingRank);
    }

    /** @param array<string, mixed> $event */
    public function advance(
        AccountProfileTransactionContext $context,
        string $consumerId,
        array $event,
    ): void {
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $profileId = $this->profileId($event);
        $context->collection(self::COLLECTION)->updateOne(
            ['_id' => $this->checkpointId($consumerId, $event)],
            [
                '$set' => [
                    'consumer_id' => $consumerId,
                    'profile_id' => $profileId,
                    'aggregate_revision' => $this->aggregateRevision($event),
                    'operation_rank' => $this->operationRank($event),
                    'event_id' => (string) ($event['event_id'] ?? ''),
                    'updated_at' => $now,
                ],
                '$setOnInsert' => [
                    'created_at' => $now,
                ],
            ],
            [...$context->rawOptions(), 'upsert' => true],
        );
    }

    /** @param array<string, mixed> $event */
    private function checkpointId(string $consumerId, array $event): string
    {
        $consumerId = trim($consumerId);
        if ($consumerId === '') {
            throw new RuntimeException('Account Profile outbox consumer id is required.');
        }

        return $consumerId.':'.$this->profileId($event);
    }

    /** @param array<string, mixed> $event */
    private function profileId(array $event): string
    {
        $profileId = trim((string) ($event['profile_id'] ?? ''));
        if ($profileId === '') {
            throw new RuntimeException('Account Profile outbox event profile id is required.');
        }

        return $profileId;
    }

    /** @param array<string, mixed> $event */
    private function aggregateRevision(array $event): int
    {
        $revision = (int) ($event['aggregate_revision'] ?? 0);
        if ($revision < 0) {
            throw new RuntimeException('Account Profile outbox event aggregate revision is required.');
        }

        return $revision;
    }

    /** @param array<string, mixed> $event */
    private function operationRank(array $event): int
    {
        return (int) ($event['operation_rank'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function documentToArray(mixed $document): ?array
    {
        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        return is_array($document) ? $document : null;
    }
}
