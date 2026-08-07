<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventAggregateWriteService
{
    public function __construct(
        private readonly EventTransactionRunner $transactions,
        private readonly EventProfileGroupMemberStore $profileGroupMemberStore,
        private readonly EventOccurrenceNestedAccountStore $occurrenceNestedAccountStore,
        private readonly EventOccurrenceSyncService $occurrenceSyncService,
        private readonly EventOccurrencePayloadSnapshotService $occurrencePayloadSnapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $occurrences
     */
    public function create(array $payload, array $occurrences): Event
    {
        /** @var Event $event */
        $event = $this->transactions->run(function () use ($payload, $occurrences): Event {
            $canonicalPayload = $payload;
            $canonicalPayload['profile_groups'] = [];

            $created = Event::query()->create($canonicalPayload);
            $this->pruneLegacyRelatedAccountFields($created);
            $this->occurrenceSyncService->syncFromEvent($created, $occurrences);

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
        $updated = $this->transactions->run(function () use ($event, $payload, $occurrences): Event {
            $canonicalPayload = $payload;
            $canonicalPayload['profile_groups'] = [];

            $event->unset('tags');
            $this->pruneLegacyRelatedAccountFields($event);
            $event->fill($canonicalPayload);
            $event->save();

            $fresh = $event->fresh() ?? $event;
            $this->occurrenceSyncService->syncFromEvent($fresh, $occurrences);

            return $fresh;
        });

        return $updated;
    }

    public function delete(Event $event): void
    {
        $eventId = (string) $event->_id;

        $this->transactions->run(function () use ($event, $eventId): null {
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
        $result = $this->transactions->run(function () use ($event, $occurrence, $groupId, $addIds, $removeIds): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $this->occurrenceNestedAccountStore->materializeLegacyIfNeeded(
                $event,
                $event->trashed(),
            );

            $existingIds = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMemberIds(
                $occurrence,
                $groupId,
            );
            $removeLookup = array_fill_keys($removeIds, true);
            $nextIds = array_values(array_filter(
                $existingIds,
                static fn (string $profileId): bool => $profileId !== '' && ! isset($removeLookup[$profileId]),
            ));
            $seen = array_fill_keys($nextIds, true);
            foreach ($addIds as $profileId) {
                if ($profileId === '' || isset($seen[$profileId])) {
                    continue;
                }
                $nextIds[] = $profileId;
                $seen[$profileId] = true;
            }

            $memberCount = $this->occurrenceNestedAccountStore->replaceOccurrenceGroupMembers(
                $occurrence,
                $groupId,
                $nextIds,
            );

            $event->touch();
            $occurrence->touch();

            $groups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence->fresh() ?? $occurrence,
                $eventId,
            );
            foreach ($groups as $group) {
                if (trim((string) ($group['id'] ?? '')) !== trim($groupId)) {
                    continue;
                }

                $group['member_count'] = $memberCount;

                return $group;
            }

            throw new NotFoundHttpException;
        });

        return $result;
    }

    public function repairOccurrences(Event $event): void
    {
        $eventId = (string) $event->_id;
        $occurrences = $this->occurrencePayloadSnapshots->resolveForRepair($event);

        if ($event->trashed()) {
            $deletedAt = $event->deleted_at;

            $this->transactions->run(function () use ($event, $eventId, $occurrences, $deletedAt): null {
                $this->profileGroupMemberStore->materializeLegacyIfNeeded($event, includeTrashedOccurrences: true);
                if ($occurrences !== []) {
                    $this->occurrenceSyncService->syncFromEvent($event, $occurrences);
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

        $this->transactions->run(function () use ($event, $occurrences): null {
            $this->profileGroupMemberStore->materializeLegacyIfNeeded($event);
            $this->occurrenceSyncService->syncFromEvent($event, $occurrences);

            return null;
        });
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
}
