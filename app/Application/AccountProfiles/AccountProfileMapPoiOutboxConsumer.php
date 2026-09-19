<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Application\MapPoiProjectionService;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;

final class AccountProfileMapPoiOutboxConsumer implements AccountProfileOutboxConsumer
{
    private const CONSUMER_ID = 'map_poi';

    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly AccountProfileProjectionCheckpointStore $checkpoints,
        private readonly MapPoiProjectionService $mapPois,
        private readonly AccountProfileAdmissionFenceService $admissionFences,
        private readonly AccountProfileLocationPolicy $locationPolicy,
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

        if ((string) ($event['operation'] ?? '') === 'tombstone') {
            $eventProfileType = trim((string) data_get($event, 'tombstone.profile_type', ''));
            if ($eventProfileType === '') {
                throw new RuntimeException('Account Profile Map POI outbox event requires a profile type.');
            }
            $this->mapPois->deleteByRef('account_profile', $profileId);
        } else {
            $projection = $event['projection'] ?? null;
            if (! is_array($projection)) {
                throw new RuntimeException('Account Profile Map POI upsert event requires an immutable projection.');
            }
            $eventProfileType = trim((string) ($projection['profile_type'] ?? ''));
            if ($eventProfileType === '') {
                throw new RuntimeException('Account Profile Map POI outbox event requires a profile type.');
            }
            try {
                $objectId = new ObjectId($profileId);
            } catch (\Throwable) {
                throw new RuntimeException('Account Profile Map POI outbox profile id is malformed.');
            }
            $currentProfile = $this->document($context->collection('account_profiles')->findOne(
                ['_id' => $objectId, 'deleted_at' => null],
                $context->rawOptions(),
            ));
            $currentProfileType = trim((string) ($currentProfile['profile_type'] ?? ''));
            $profileTypes = $this->admissionFences->touchProfileTypes(
                $context->database(),
                $context->session(),
                [$eventProfileType, $currentProfileType],
            );

            if ($currentProfile === null) {
                $this->mapPois->deleteByRef('account_profile', $profileId);
            } else {
                $currentType = $this->profileType($profileTypes[$currentProfileType] ?? null);
                $this->mapPois->upsertFromAccountProfile(
                    (object) $currentProfile,
                    (int) ($projection['source_checkpoint'] ?? 0),
                    resolvedMapPoiEnabled: $currentType instanceof TenantProfileType
                        && $this->locationPolicy->isMapProjectionEnabledForType($currentType),
                );
            }
        }

        // The projection effect and this monotonic tuple commit together.
        $this->checkpoints->advance($context, $this->consumerId(), $event);
    }

    /** @param array<string, mixed> $event */
    public function consumeTypeReconcilePage(AccountProfileTransactionContext $context, array $event): bool
    {
        $eventId = trim((string) ($event['_id'] ?? ''));
        $claimToken = trim((string) ($event['claim_token'] ?? ''));
        $profileType = trim((string) ($event['profile_type'] ?? ''));
        $expectedRevision = (int) ($event['capability_revision'] ?? -1);
        if ($eventId === '' || $claimToken === '' || $profileType === '' || $expectedRevision < 0) {
            throw new RuntimeException('Map POI type reconciliation item is malformed.');
        }

        $type = $this->document($context->collection('account_profile_types')->findOne(
            ['type' => $profileType],
            $context->rawOptions(),
        ));
        if ($type === null) {
            throw new RuntimeException('Map POI type reconciliation cannot resolve its profile type.');
        }
        $currentRevision = (int) ($type['capability_revision'] ?? -1);
        if ($currentRevision < $expectedRevision) {
            throw new RuntimeException('Map POI type reconciliation generation is ahead of the profile type.');
        }
        if ($currentRevision > $expectedRevision) {
            $this->finishTypeItem($context, $eventId, $claimToken, completed: true, afterProfileId: null);

            return false;
        }

        $profileTypes = $this->admissionFences->touchProfileTypes(
            $context->database(),
            $context->session(),
            [$profileType],
            [$profileType => $expectedRevision],
        );
        $fencedType = $this->profileType($profileTypes[$profileType] ?? null);
        $filter = ['profile_type' => $profileType, 'deleted_at' => null];
        $after = trim((string) ($event['after_profile_id'] ?? ''));
        if ($after !== '') {
            try {
                $filter['_id'] = ['$gt' => new ObjectId($after)];
            } catch (\Throwable) {
                throw new RuntimeException('Map POI type reconciliation cursor is malformed.');
            }
        }

        $rows = $context->collection('account_profiles')->find($filter, [
            ...$context->rawOptions(),
            'sort' => ['_id' => 1],
            'limit' => self::PAGE_SIZE + 1,
        ])->toArray();
        $hasNextPage = count($rows) > self::PAGE_SIZE;
        $lastId = null;
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $profile = $this->document($row);
            if ($profile === null) {
                continue;
            }
            $lastId = trim((string) ($profile['_id'] ?? ''));
            $this->mapPois->upsertFromAccountProfile(
                (object) $profile,
                (int) ($event['source_checkpoint'] ?? 0),
                resolvedMapPoiEnabled: $fencedType instanceof TenantProfileType
                    && $this->locationPolicy->isMapProjectionEnabledForType($fencedType),
            );
        }

        $this->finishTypeItem(
            $context,
            $eventId,
            $claimToken,
            completed: ! $hasNextPage,
            afterProfileId: $hasNextPage ? $lastId : null,
        );

        return $hasNextPage;
    }

    private function finishTypeItem(
        AccountProfileTransactionContext $context,
        string $eventId,
        string $claimToken,
        bool $completed,
        ?string $afterProfileId,
    ): void {
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $set = [
            'delivery_state' => $completed ? 'completed' : 'pending',
            'after_profile_id' => $afterProfileId,
            'updated_at' => $now,
        ];
        if ($completed) {
            $set['delivered_at'] = $now;
        }
        $result = $context->collection('account_profile_outbox')->updateOne([
            '_id' => $eventId,
            'delivery_state' => 'claimed',
            'claim_token' => $claimToken,
        ], [
            '$set' => $set,
            '$unset' => [
                'claim_token' => true,
                'claim_expires_at' => true,
                'last_delivery_error' => true,
            ],
        ], $context->rawOptions());
        if ($result->getModifiedCount() !== 1) {
            throw new RuntimeException('Map POI type reconciliation claim changed concurrently.');
        }
    }

    private function profileType(mixed $document): ?TenantProfileType
    {
        if (! is_array($document)) {
            return null;
        }

        $type = new TenantProfileType;
        $type->setRawAttributes($document, true);
        $type->exists = true;

        return $type;
    }

    /** @return array<string, mixed>|null */
    private function document(mixed $value): ?array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (! is_array($value)) {
            return null;
        }
        foreach ($value as $key => $item) {
            if ($item instanceof BSONDocument || $item instanceof BSONArray || $item instanceof \Traversable) {
                $value[$key] = $this->document($item) ?? [];
            }
        }

        return $value;
    }
}
