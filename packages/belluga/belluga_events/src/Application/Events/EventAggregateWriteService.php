<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Contracts\EventContentSanitizerContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventAggregateWriteService
{
    public function __construct(
        private readonly EventTransactionRunner $transactions,
        private readonly EventProfileGroupMemberStore $profileGroupMemberStore,
        private readonly EventOccurrenceNestedAccountStore $occurrenceNestedAccountStore,
        private readonly EventOccurrenceSyncService $occurrenceSyncService,
        private readonly EventOccurrencePayloadSnapshotService $occurrencePayloadSnapshots,
        private readonly EventContentSanitizerContract $contentSanitizer,
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
        $updated = $this->transactions->run(function () use ($event, $payload, $occurrences): Event {
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
            );

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

    /**
     * @return array<string, mixed>
     */
    public function createOccurrenceGroup(
        Event $event,
        EventOccurrence $occurrence,
        string $label,
    ): array {
        /** @var array<string, mixed> $result */
        $result = $this->transactions->run(function () use ($event, $occurrence, $label): array {
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

            $this->occurrenceNestedAccountStore->materializeLegacyIfNeeded(
                $event,
                $event->trashed(),
            );

            $existingGroups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence,
                $eventId,
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

            $metadataOnly = $this->profileGroupMemberStore->metadataOnly($nextGroups);
            $occurrence->forceFill([
                'own_profile_groups' => $metadataOnly,
                'profile_groups' => $metadataOnly,
            ]);
            $occurrence->save();

            $freshOccurrence = $occurrence->fresh() ?? $occurrence;
            $this->occurrenceNestedAccountStore->syncOccurrenceGroupMetadata(
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
        $result = $this->transactions->run(function () use ($event, $occurrence, $groupId): array {
            $eventId = trim((string) $event->getKey());
            $occurrenceId = trim((string) $occurrence->getKey());
            if ($eventId === '' || $occurrenceId === '' || trim((string) ($occurrence->event_id ?? '')) !== $eventId) {
                throw new NotFoundHttpException;
            }

            $this->occurrenceNestedAccountStore->materializeLegacyIfNeeded(
                $event,
                $event->trashed(),
            );

            $existingGroups = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                $occurrence,
                $eventId,
            );
            $group = $this->findOccurrenceGroupOrFail($existingGroups, $groupId);
            $memberIds = $this->occurrenceNestedAccountStore->adminOccurrenceGroupMemberIds(
                $occurrence,
                (string) $group['id'],
            );
            if (count($memberIds) > InputConstraints::EVENT_PROFILE_GROUP_MEMBERS_MAX) {
                throw ValidationException::withMessages([
                    'profile_groups' => ['Related-account group delete exceeds the approved member budget.'],
                ]);
            }

            $nextGroups = [];
            foreach ($existingGroups as $candidate) {
                if (trim((string) ($candidate['id'] ?? '')) === (string) $group['id']) {
                    continue;
                }

                $nextGroups[] = [
                    'id' => trim((string) ($candidate['id'] ?? '')),
                    'label' => trim((string) ($candidate['label'] ?? '')),
                    'order' => count($nextGroups),
                ];
            }

            $metadataOnly = $this->profileGroupMemberStore->metadataOnly($nextGroups);
            $occurrence->forceFill([
                'own_profile_groups' => $metadataOnly,
                'profile_groups' => $metadataOnly,
            ]);
            $occurrence->save();

            $freshOccurrence = $occurrence->fresh() ?? $occurrence;
            $this->occurrenceNestedAccountStore->syncOccurrenceGroupMetadata(
                $eventId,
                $freshOccurrence,
                $metadataOnly,
            );

            $event->touch();
            $freshOccurrence = $freshOccurrence->fresh() ?? $freshOccurrence;

            return [
                'occurrence_id' => (string) $freshOccurrence->getKey(),
                'deleted_group_id' => (string) $group['id'],
                'profile_groups' => $this->occurrenceNestedAccountStore->adminOccurrenceGroupMetadata(
                    $freshOccurrence,
                    $eventId,
                ),
            ];
        });

        return $result;
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
                $this->profileGroupMemberStore->materializeLegacyIfNeeded($event, includeTrashedOccurrences: true);
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
            $this->profileGroupMemberStore->materializeLegacyIfNeeded($event);
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
