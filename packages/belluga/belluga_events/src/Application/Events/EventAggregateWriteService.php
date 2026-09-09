<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Contracts\EventContentSanitizerContract;
use Belluga\Events\Contracts\EventMapPoiDeletionContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventAggregateWriteService
{
    public function __construct(
        private readonly EventTransactionRunner $transactions,
        private readonly EventOccurrenceNestedAccountStore $occurrenceNestedAccountStore,
        private readonly EventOccurrenceSyncService $occurrenceSyncService,
        private readonly EventOccurrencePayloadSnapshotService $occurrencePayloadSnapshots,
        private readonly EventContentSanitizerContract $contentSanitizer,
        private readonly EventMapPoiDeletionContract $mapPoiDeletion,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function create(array $payload, array $occurrences): Event
    {
        /** @var Event $event */
        $event = $this->transactions->run(function (EventTransactionContext $context) use ($payload, $occurrences): Event {
            $canonicalPayload = $payload;
            $canonicalPayload['profile_groups'] = [];
            $canonicalContent = $this->canonicalEventContent(
                $payload['content'] ?? null,
            );
            $canonicalPayload['content'] = $canonicalContent;

            $created = Event::query()->create($canonicalPayload);
            $this->pruneLegacyRelatedAccountFields($created);
            $this->occurrenceSyncService->syncFromEvent(
                $created,
                $occurrences,
                $canonicalContent,
                $context,
            );

            return $created->fresh() ?? $created;
        });

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function update(Event $event, array $payload, array $occurrences): Event
    {
        /** @var Event $updated */
        $updated = $this->transactions->run(function (EventTransactionContext $context) use ($event, $payload, $occurrences): Event {
            $canonicalPayload = $payload;
            $canonicalPayload['profile_groups'] = [];
            $canonicalContent = $this->canonicalEventContent(
                array_key_exists('content', $payload)
                    ? $payload['content']
                    : $event->content,
            );
            $canonicalPayload['content'] = $canonicalContent;

            $event->unset('tags');
            $this->pruneLegacyRelatedAccountFields($event);
            $event->fill($canonicalPayload);
            $event->save();

            $fresh = $event->fresh() ?? $event;
            $this->occurrenceSyncService->syncFromEvent(
                $fresh,
                $occurrences,
                $canonicalContent,
                $context,
            );

            return $fresh;
        });

        return $updated;
    }

    public function delete(Event $event): void
    {
        $eventId = (string) $event->_id;

        $this->transactions->run(function (EventTransactionContext $context) use ($event, $eventId): null {
            $this->occurrenceNestedAccountStore->purgeByEventIdWithinContext($context, $eventId);
            $this->mapPoiDeletion->deleteForEvent($context, $eventId);
            $event->delete();
            $this->occurrenceSyncService->softDeleteByEventId($eventId);

            return null;
        });
    }

    /**
     * @param  array<int, string>  $addIds
     * @param  array<int, string>  $removeIds
     * @return array<string, mixed>
     */
    public function patchOccurrenceGroupMembers(
        Event $event,
        EventOccurrence $occurrence,
        string $groupId,
        array $addIds,
        array $removeIds,
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->run(function (EventTransactionContext $context) use ($event, $occurrence, $groupId, $addIds, $removeIds): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $this->occurrenceNestedAccountStore->patchOccurrenceGroupMembersWithinContext(
                $context,
                $occurrence,
                $groupId,
                $addIds,
                $removeIds,
            );

            $event->touch();
            $occurrence->touch();

            $groups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence->fresh() ?? $occurrence,
                $eventId,
                $context,
            );
            foreach ($groups as $group) {
                if (trim((string) ($group['id'] ?? '')) !== trim($groupId)) {
                    continue;
                }

                return $group;
            }

            throw new NotFoundHttpException;
        });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function createOccurrenceGroup(
        Event $event,
        EventOccurrence $occurrence,
        string $label,
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->run(function (EventTransactionContext $context) use ($event, $occurrence, $label): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $normalizedLabel = trim($label);
            if ($normalizedLabel === '') {
                throw ValidationException::withMessages([
                    'label' => ['Related-account group label is required.'],
                ]);
            }

            $existingGroups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence,
                $eventId,
                $context,
            );
            if (count($existingGroups) >= InputConstraints::EVENT_PROFILE_GROUPS_MAX) {
                throw ValidationException::withMessages([
                    'profile_groups' => ['Related-account groups exceed the configured limit.'],
                ]);
            }

            $nextGroups = $this->normalizeOccurrenceGroupPayloads([
                ...$existingGroups,
                [
                    'id' => $this->nextOccurrenceGroupId($existingGroups, $normalizedLabel),
                    'label' => $normalizedLabel,
                    'order' => count($existingGroups),
                ],
            ]);

            $metadataOnly = $this->occurrenceNestedAccountStore->metadataOnly($nextGroups);
            $occurrence->forceFill([
                'own_profile_groups' => $metadataOnly,
                'profile_groups' => $metadataOnly,
            ]);
            $occurrence->save();

            $freshOccurrence = $occurrence->fresh() ?? $occurrence;
            $this->occurrenceNestedAccountStore->syncOccurrenceGroupMetadataWithinContext(
                $context,
                $eventId,
                $freshOccurrence,
                $metadataOnly,
            );

            $event->touch();
            $freshOccurrence = $freshOccurrence->fresh() ?? $freshOccurrence;

            return [
                'occurrence_id' => (string) $freshOccurrence->getKey(),
                'profile_groups' => $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                    $freshOccurrence,
                    $eventId,
                    $context,
                ),
            ];
        });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteOccurrenceGroup(
        Event $event,
        EventOccurrence $occurrence,
        string $groupId,
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->run(function (EventTransactionContext $context) use ($event, $occurrence, $groupId): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $existingGroups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence,
                $eventId,
                $context,
            );
            $group = $this->findOccurrenceGroupOrFail($existingGroups, $groupId);
            $this->occurrenceNestedAccountStore->deleteOccurrenceGroupWithinContext(
                $context,
                $eventId,
                $occurrenceId,
                (string) $group['id'],
            );
            $nextGroups = array_values(array_map(
                static function (array $candidate) use ($group): array {
                    if ((int) ($candidate['order'] ?? 0) > (int) ($group['order'] ?? 0)) {
                        $candidate['order'] = (int) $candidate['order'] - 1;
                    }

                    return [
                        '_id' => (string) ($candidate['id'] ?? ''),
                        'label' => (string) ($candidate['label'] ?? ''),
                        'order' => (int) ($candidate['order'] ?? 0),
                    ];
                },
                array_values(array_filter(
                    $existingGroups,
                    static fn (array $candidate): bool => (string) ($candidate['id'] ?? '') !== (string) $group['id'],
                )),
            ));
            $context->collection('event_occurrences')->updateOne(
                ['_id' => new ObjectId($occurrenceId), 'event_id' => $eventId, 'deleted_at' => null],
                ['$set' => ['own_profile_groups' => $nextGroups, 'profile_groups' => $nextGroups]],
                $context->rawOptions(),
            );

            $freshOccurrence = $occurrence->fresh() ?? $occurrence;

            return [
                'occurrence_id' => (string) $freshOccurrence->getKey(),
                'deleted_group_id' => (string) $group['id'],
                'profile_groups' => $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                    $freshOccurrence,
                    $eventId,
                    $context,
                ),
            ];
        });

        return $result;
    }

    /** @return array{id:string,label:string,_changed:bool} */
    public function renameOccurrenceGroup(
        Event $event,
        EventOccurrence $occurrence,
        string $groupId,
        string $label,
    ): array {
        return $this->transactions->run(function (EventTransactionContext $context) use ($event, $occurrence, $groupId, $label): array {
            $eventId = trim((string) $event->getKey());
            if ($eventId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }
            $label = trim($label);
            if ($label === '') {
                throw ValidationException::withMessages(['label' => ['Related-account group label is required.']]);
            }
            $updated = $this->occurrenceNestedAccountStore->renameOccurrenceGroupLabel(
                $context,
                $eventId,
                (string) $occurrence->getKey(),
                $groupId,
                $label,
            );
            if (! $updated['_changed']) {
                return $updated;
            }

            try {
                $occurrenceId = new ObjectId((string) $occurrence->getKey());
                $eventObjectId = new ObjectId($eventId);
            } catch (\Throwable) {
                throw new NotFoundHttpException;
            }
            $occurrenceUpdate = $context->collection('event_occurrences')->updateOne(
                [
                    '_id' => $occurrenceId,
                    'event_id' => $eventId,
                    'deleted_at' => null,
                    'own_profile_groups._id' => (string) $updated['id'],
                    'profile_groups._id' => (string) $updated['id'],
                ],
                ['$set' => [
                    'own_profile_groups.$[group].label' => $label,
                    'profile_groups.$[group].label' => $label,
                    'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                ]],
                [...$context->rawOptions(), 'arrayFilters' => [['group._id' => (string) $updated['id']]]],
            );
            if ($occurrenceUpdate->getMatchedCount() !== 1 || $occurrenceUpdate->getModifiedCount() !== 1) {
                throw new RuntimeException('Event occurrence nested group mirror changed during rename.');
            }
            $eventUpdate = $context->collection('events')->updateOne(
                ['_id' => $eventObjectId],
                ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
                $context->rawOptions(),
            );
            if ($eventUpdate->getMatchedCount() !== 1) {
                throw new RuntimeException('Event root changed during occurrence group rename.');
            }

            return $updated;
        });
    }

    /** @return array{event_id:string,occurrence_id:string,groups:array<int, array{id:string,order:int}>,_changed:bool} */
    public function moveOccurrenceGroup(
        Event $event,
        EventOccurrence $occurrence,
        string $groupId,
        string $direction,
    ): array {
        return $this->transactions->run(function (EventTransactionContext $context) use ($event, $occurrence, $groupId, $direction): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $groups = $this->occurrenceNestedAccountStore->orderedGroupPositionsWithinContext($context, $eventId, $occurrenceId);
            $this->assertOccurrenceGroupOrderParity($groups, $occurrence->own_profile_groups ?? [], 'own_profile_groups');
            $this->assertOccurrenceGroupOrderParity($groups, $occurrence->profile_groups ?? [], 'profile_groups');
            $index = array_search(trim($groupId), array_column($groups, 'id'), true);
            if ($index === false) {
                throw new NotFoundHttpException;
            }
            $neighborIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (! isset($groups[$neighborIndex])) {
                return ['event_id' => $eventId, 'occurrence_id' => $occurrenceId, 'groups' => $groups, '_changed' => false];
            }

            $moved = $groups[$index];
            $neighbor = $groups[$neighborIndex];
            $this->occurrenceNestedAccountStore->swapAdjacentGroupOrdersWithinContext(
                $context, $eventId, $occurrenceId, $moved, $neighbor,
            );
            $occurrenceUpdate = $context->collection('event_occurrences')->updateOne([
                '_id' => new ObjectId($occurrenceId),
                'event_id' => $eventId,
                'deleted_at' => null,
                'own_profile_groups' => ['$all' => [
                    ['$elemMatch' => ['_id' => $moved['id'], 'order' => $moved['order']]],
                    ['$elemMatch' => ['_id' => $neighbor['id'], 'order' => $neighbor['order']]],
                ]],
                'profile_groups' => ['$all' => [
                    ['$elemMatch' => ['_id' => $moved['id'], 'order' => $moved['order']]],
                    ['$elemMatch' => ['_id' => $neighbor['id'], 'order' => $neighbor['order']]],
                ]],
            ], ['$set' => [
                'own_profile_groups.$[moved].order' => $neighbor['order'],
                'own_profile_groups.$[neighbor].order' => $moved['order'],
                'profile_groups.$[moved].order' => $neighbor['order'],
                'profile_groups.$[neighbor].order' => $moved['order'],
                'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
            ]], [...$context->rawOptions(), 'arrayFilters' => [
                ['moved._id' => $moved['id'], 'moved.order' => $moved['order']],
                ['neighbor._id' => $neighbor['id'], 'neighbor.order' => $neighbor['order']],
            ]]);
            if ($occurrenceUpdate->getMatchedCount() !== 1 || $occurrenceUpdate->getModifiedCount() !== 1) {
                throw new RuntimeException('Event occurrence nested group mirror changed during reorder.');
            }
            $eventUpdate = $context->collection('events')->updateOne(
                ['_id' => new ObjectId($eventId)],
                ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
                $context->rawOptions(),
            );
            if ($eventUpdate->getMatchedCount() !== 1) {
                throw new RuntimeException('Event root changed during occurrence group reorder.');
            }

            $groups[$index]['order'] = $neighbor['order'];
            $groups[$neighborIndex]['order'] = $moved['order'];
            usort($groups, static fn (array $left, array $right): int => [$left['order'], $left['id']] <=> [$right['order'], $right['id']]);

            return ['event_id' => $eventId, 'occurrence_id' => $occurrenceId, 'groups' => array_values($groups), '_changed' => true];
        });
    }

    public function repairOccurrences(Event $event): void
    {
        $eventId = (string) $event->_id;
        try {
            $occurrences = $this->occurrencePayloadSnapshots->resolveForRepair($event);
        } catch (RuntimeException $exception) {
            Log::warning('events_occurrence_reconciliation_skipped_schedule_overflow', [
                'event_id' => $eventId,
                'reason' => $exception->getMessage(),
            ]);

            return;
        }
        $canonicalContent = $this->canonicalEventContent($event->content);

        if ($event->trashed()) {
            $deletedAt = $event->deleted_at;

            $this->transactions->run(function () use ($event, $eventId, $occurrences, $deletedAt, $canonicalContent): null {
                if ($occurrences !== []) {
                    $this->occurrenceSyncService->syncFromEvent(
                        $event,
                        $occurrences,
                        $canonicalContent,
                    );
                }

                $this->occurrenceSyncService->softDeleteByEventId($eventId, $deletedAt);

                return null;
            });

            return;
        }

        if ($occurrences === []) {
            Log::warning('events_occurrence_reconciliation_skipped_missing_schedule', [
                'event_id' => $eventId,
            ]);

            return;
        }

        $this->transactions->run(function () use ($event, $occurrences, $canonicalContent): null {
            if ((string) ($event->content ?? '') !== $canonicalContent) {
                $event->forceFill(['content' => $canonicalContent])->saveQuietly();
            }
            $this->occurrenceSyncService->syncFromEvent(
                $event,
                $occurrences,
                $canonicalContent,
            );

            return null;
        });
    }

    /** @param array<int, array{id:string,order:int}> $heads */
    private function assertOccurrenceGroupOrderParity(array $heads, mixed $rawMirror, string $mirrorName): void
    {
        if ($rawMirror instanceof \MongoDB\Model\BSONArray) {
            $rawMirror = $rawMirror->getArrayCopy();
        }
        if (! is_array($rawMirror)) {
            throw new RuntimeException("Event occurrence {$mirrorName} is malformed.");
        }
        $mirror = [];
        foreach ($rawMirror as $group) {
            if ($group instanceof \MongoDB\Model\BSONDocument) {
                $group = $group->getArrayCopy();
            }
            if (! is_array($group)) {
                throw new RuntimeException("Event occurrence {$mirrorName} is malformed.");
            }
            $mirror[] = ['id' => trim((string) ($group['id'] ?? $group['_id'] ?? '')), 'order' => (int) ($group['order'] ?? -1)];
        }
        usort($mirror, static fn (array $left, array $right): int => [$left['order'], $left['id']] <=> [$right['order'], $right['id']]);

        if ($heads === [] && $mirror === []) {
            return;
        }

        $ids = array_column($heads, 'id');
        $orders = array_column($heads, 'order');
        if ($ids === [] || count($ids) !== count(array_unique($ids)) || $orders !== range(0, count($heads) - 1) || $mirror !== $heads) {
            throw new RuntimeException("Event occurrence {$mirrorName} order is inconsistent.");
        }
    }

    private function canonicalEventContent(mixed $value): string
    {
        $canonical = $this->contentSanitizer->sanitize(
            is_string($value) ? $value : null,
            allowExplicitHttpsLinks: true,
        );
        if (strlen($canonical) > InputConstraints::RICH_TEXT_MAX_BYTES) {
            throw ValidationException::withMessages([
                'content' => ['The content may not be greater than 100 KB after sanitization.'],
            ]);
        }

        return $canonical;
    }

    /**
     * @return array{published: bool, from_status?: string, to_status?: string, publish_at?: mixed, mirrored_occurrences?: int}
     */
    public function publishScheduledEventIfDue(string $eventId, Carbon $now): array
    {
        /** @var array{published: bool, from_status?: string, to_status?: string, publish_at?: mixed, mirrored_occurrences?: int} $result */
        $result = $this->transactions->run(function () use ($eventId, $now): array {
            $event = Event::query()->where('_id', $eventId)->first();
            if (! $event) {
                return ['published' => false];
            }

            $publication = is_array($event->publication ?? null)
                ? $event->publication
                : (array) ($event->publication ?? []);
            $fromStatus = (string) ($publication['status'] ?? 'draft');

            if ($fromStatus !== 'publish_scheduled') {
                return ['published' => false];
            }

            $publishAt = $this->toCarbon($publication['publish_at'] ?? null);
            if ($publishAt !== null && $publishAt->greaterThan($now)) {
                return ['published' => false];
            }

            $publication['status'] = 'published';
            if (! isset($publication['publish_at'])) {
                $publication['publish_at'] = $now;
            }

            $event->publication = $publication;
            $event->save();

            $mirrored = $this->occurrenceSyncService->mirrorPublicationByEventId($eventId, $publication, $now);

            return [
                'published' => true,
                'from_status' => $fromStatus,
                'to_status' => 'published',
                'publish_at' => $publication['publish_at'] ?? null,
                'mirrored_occurrences' => (int) $mirrored,
            ];
        });

        return $result;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function pruneLegacyRelatedAccountFields(Event $event): void
    {
        $event->unset('artists');
        $event->unset('event_parties');
        $event->unset('account_context_ids');
        $event->unset('linked_account_profiles');
        $event->unset('own_linked_account_profiles');
        $event->unset('own_event_parties');
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrenceGroupPayloads(array $groups): array
    {
        $normalized = [];
        foreach ($groups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            $label = trim((string) ($group['label'] ?? ''));
            if ($groupId === '' || $label === '') {
                continue;
            }

            $normalized[] = [
                'id' => $groupId,
                'label' => $label,
                'order' => count($normalized),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function findOccurrenceGroupOrFail(array $groups, string $groupId): array
    {
        $targetId = trim($groupId);
        foreach ($groups as $group) {
            if (trim((string) ($group['id'] ?? '')) === $targetId) {
                return $group;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existingGroups
     */
    private function nextOccurrenceGroupId(array $existingGroups, string $label): string
    {
        $usedIds = [];
        foreach ($existingGroups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId !== '') {
                $usedIds[$groupId] = true;
            }
        }

        $base = trim(Str::slug($label), '-_');
        if ($base === '') {
            $base = 'grupo';
        }

        $base = substr($base, 0, InputConstraints::EVENT_PROFILE_GROUP_KEY_MAX);
        $base = rtrim($base, '-_');
        if ($base === '') {
            $base = 'grupo';
        }

        $candidate = $base;
        $suffix = 2;
        while (isset($usedIds[$candidate])) {
            $suffixText = '-'.$suffix;
            $prefixLength = max(1, InputConstraints::EVENT_PROFILE_GROUP_KEY_MAX - strlen($suffixText));
            $candidate = rtrim(substr($base, 0, $prefixLength), '-_');
            if ($candidate === '') {
                $candidate = 'grupo';
            }
            $candidate .= $suffixText;
            $suffix++;
        }

        return $candidate;
    }
}
