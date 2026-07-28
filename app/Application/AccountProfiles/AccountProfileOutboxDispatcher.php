<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\RuntimeException as MongoRuntimeException;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;
use RuntimeException;
use Throwable;

final class AccountProfileOutboxDispatcher
{
    private const OUTBOX_COLLECTION = 'account_profile_outbox';

    private const CLAIM_TTL_SECONDS = 30;

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileMapPoiOutboxConsumer $mapPoiConsumer,
        private readonly AccountProfileInviteablePeopleOutboxConsumer $inviteablePeopleConsumer,
    ) {}

    public function dispatchEvent(string $eventId): bool
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            throw new RuntimeException('Account Profile outbox event id is required.');
        }

        $claimToken = (string) Str::uuid();
        $event = $this->claim($eventId, $claimToken);
        if ($event === null) {
            return false;
        }

        try {
            $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($event): void {
                foreach ($this->consumers() as $consumer) {
                    $consumer->consume($context, $event);
                }
            });
        } catch (Throwable $exception) {
            $this->release($eventId, $claimToken, $exception);

            throw $exception;
        }

        $this->complete($eventId, $claimToken);

        return true;
    }

    public function dispatchAvailable(int $limit = 100): int
    {
        $limit = max(1, min($limit, 500));
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $cursor = $this->collection()->find(
            [
                '$or' => [
                    ['delivery_state' => ['$exists' => false]],
                    ['delivery_state' => 'pending'],
                    [
                        'delivery_state' => 'claimed',
                        'claim_expires_at' => ['$lte' => $now],
                    ],
                ],
            ],
            [
                'projection' => ['_id' => 1],
                'sort' => ['occurred_at' => 1, '_id' => 1],
                'limit' => $limit,
            ],
        );

        $delivered = 0;
        foreach ($cursor as $event) {
            $eventId = trim((string) ($event['_id'] ?? ''));
            if ($eventId !== '' && $this->dispatchEvent($eventId)) {
                $delivered++;
            }
        }

        return $delivered;
    }

    /** @return array<string, mixed>|null */
    private function claim(string $eventId, string $claimToken): ?array
    {
        $now = now();
        $nowUtc = new UTCDateTime((int) $now->getTimestampMs());
        $expiresAt = new UTCDateTime((int) $now->copy()->addSeconds(self::CLAIM_TTL_SECONDS)->getTimestampMs());

        try {
            $event = $this->collection()->findOneAndUpdate(
                [
                    '_id' => $eventId,
                    '$or' => [
                        ['delivery_state' => ['$exists' => false]],
                        ['delivery_state' => 'pending'],
                        [
                            'delivery_state' => 'claimed',
                            'claim_expires_at' => ['$lte' => $nowUtc],
                        ],
                    ],
                ],
                [
                    '$set' => [
                        'delivery_state' => 'claimed',
                        'claim_token' => $claimToken,
                        'claim_expires_at' => $expiresAt,
                        'claimed_at' => $nowUtc,
                    ],
                    '$inc' => ['delivery_attempts' => 1],
                ],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );
        } catch (MongoRuntimeException $exception) {
            throw new RuntimeException('Account Profile outbox claim failed.', previous: $exception);
        }

        return $this->documentToArray($event);
    }

    private function complete(string $eventId, string $claimToken): void
    {
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $this->collection()->updateOne(
            [
                '_id' => $eventId,
                'delivery_state' => 'claimed',
                'claim_token' => $claimToken,
            ],
            [
                '$set' => [
                    'delivery_state' => 'completed',
                    'delivered_at' => $now,
                    'updated_at' => $now,
                ],
                '$unset' => [
                    'claim_token' => true,
                    'claim_expires_at' => true,
                    'last_delivery_error' => true,
                ],
            ],
        );
    }

    private function release(string $eventId, string $claimToken, Throwable $exception): void
    {
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $this->collection()->updateOne(
            [
                '_id' => $eventId,
                'delivery_state' => 'claimed',
                'claim_token' => $claimToken,
            ],
            [
                '$set' => [
                    'delivery_state' => 'pending',
                    'last_delivery_error' => mb_substr($exception->getMessage(), 0, 500),
                    'updated_at' => $now,
                ],
                '$unset' => [
                    'claim_token' => true,
                    'claim_expires_at' => true,
                ],
            ],
        );
    }

    private function collection(): \MongoDB\Collection
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Account Profile outbox delivery.');
        }

        return $connection->getDatabase()->selectCollection(self::OUTBOX_COLLECTION);
    }

    /** @return list<AccountProfileOutboxConsumer> */
    private function consumers(): array
    {
        return [$this->mapPoiConsumer, $this->inviteablePeopleConsumer];
    }

    /** @return array<string, mixed>|null */
    private function documentToArray(mixed $document): ?array
    {
        if ($document instanceof BSONDocument) {
            $document = $document->getArrayCopy();
        }

        return is_array($document) ? $this->normalizeValue($document) : null;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        }
        if ($value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $entry) {
            $value[$key] = $this->normalizeValue($entry);
        }

        return $value;
    }
}
