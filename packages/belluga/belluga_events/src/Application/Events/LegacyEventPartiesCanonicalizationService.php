<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LegacyEventPartiesCanonicalizationService
{
    public function __construct(
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventPartyMapperRegistryContract $eventPartyMappers,
        private readonly EventOccurrenceReconciliationService $occurrenceReconciliationService,
        private readonly EventQueryService $eventQueryService,
        private readonly EventProfileGroupMemberStore $profileGroupMemberStore,
        private readonly EventOccurrenceNestedAccountStore $nestedAccountStore,
    ) {}

    /**
     * @return array{scanned:int, invalid:int, repaired:int, unchanged:int, failed:int}
     */
    public function inspect(): array
    {
        return $this->run(applyRepair: false);
    }

    /**
     * @return array{scanned:int, invalid:int, repaired:int, unchanged:int, failed:int}
     */
    public function repair(): array
    {
        return $this->run(applyRepair: true);
    }

    /**
     * @return array{scanned:int, invalid:int, repaired:int, unchanged:int, failed:int}
     */
    private function run(bool $applyRepair): array
    {
        $summary = [
            'scanned' => 0,
            'invalid' => 0,
            'repaired' => 0,
            'unchanged' => 0,
            'failed' => 0,
        ];

        Event::withTrashed()
            ->orderBy('_id')
            ->cursor()
            ->each(function (Event $event) use (&$summary, $applyRepair): void {
                $summary['scanned']++;

                $analysis = $this->analyze($event);
                if (! $analysis['invalid']) {
                    $summary['unchanged']++;

                    return;
                }

                $summary['invalid']++;
                if (! $applyRepair) {
                    return;
                }

                try {
                    $this->repairEvent($event, $analysis);
                    $summary['repaired']++;
                } catch (\Throwable $throwable) {
                    $summary['failed']++;

                    Log::warning('legacy_event_parties_canonicalization_failed', [
                        'event_id' => (string) $event->_id,
                        'message' => $throwable->getMessage(),
                    ]);
                }
            });

        if ($applyRepair) {
            $summary['unchanged'] = max(
                0,
                $summary['scanned'] - $summary['repaired'] - $summary['failed']
            );
        }

        return $summary;
    }

    /**
     * @return array{
     *   invalid: bool,
     *   has_legacy_artists: bool,
     *   has_venue_party: bool,
     *   has_invalid_management_payload: bool,
     *   has_legacy_profile_group_members: bool,
     *   has_missing_occurrence_canonical_ownership: bool,
     *   target_artist_ids: array<int, string>,
     *   canonical_artist_ids: array<int, string>,
     *   artist_parties_by_id: array<string, array<string, mixed>>,
     *   occurrence_repairs: array<int, array{
     *     occurrence_id: string,
     *     own_event_parties: array<int, array<string, mixed>>,
     *     profile_groups: array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     *   }>
     * }
     */
    private function analyze(Event $event): array
    {
        $eventParties = $this->normalizeArray($event->event_parties ?? []);
        $legacyArtists = $this->normalizeArray($event->artists ?? []);

        $hasVenueParty = false;
        $hasLegacyArtists = false;
        $targetArtistIds = [];
        $canonicalArtistIds = [];
        $artistPartiesById = [];
        $missingCanonicalMetadata = false;
        $managementPayloadIssues = $this->analyzeManagementPayloadContract($event);
        $hasLegacyProfileGroupMembers = $this->hasLegacyProfileGroupMembers($event);
        $occurrenceCanonicalization = $this->analyzeOccurrenceCanonicalization($event);

        foreach ($legacyArtists as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            $artistId = $this->resolveLegacyArtistId($artist);
            if ($artistId === '') {
                continue;
            }

            $hasLegacyArtists = true;
            $targetArtistIds[] = $artistId;
        }

        foreach ($eventParties as $party) {
            if (! is_array($party)) {
                continue;
            }

            $partyType = trim((string) ($party['party_type'] ?? ''));
            $partyRefId = trim((string) ($party['party_ref_id'] ?? ''));
            if ($partyType === 'venue') {
                $hasVenueParty = true;

                continue;
            }

            if ($partyRefId === '') {
                continue;
            }

            $canonicalArtistIds[] = $partyRefId;
            $artistPartiesById[$partyRefId] = $party;

            $metadata = isset($party['metadata']) && is_array($party['metadata'])
                ? $party['metadata']
                : [];
            $hasSlug = trim((string) ($metadata['slug'] ?? '')) !== '';
            $hasDisplayName = trim((string) ($metadata['display_name'] ?? '')) !== '';
            $hasProfileType = trim((string) ($metadata['profile_type'] ?? '')) !== '';
            if (! $hasSlug || ! $hasDisplayName || ! $hasProfileType) {
                $missingCanonicalMetadata = true;
            }
        }

        $targetArtistIds = array_values(array_unique(array_merge($targetArtistIds, $canonicalArtistIds)));
        $canonicalArtistIds = array_values(array_unique($canonicalArtistIds));

        $invalid = $hasLegacyArtists
            || $hasVenueParty
            || $missingCanonicalMetadata
            || $hasLegacyProfileGroupMembers
            || $occurrenceCanonicalization['invalid']
            || $targetArtistIds !== $canonicalArtistIds
            || $managementPayloadIssues !== [];

        return [
            'invalid' => $invalid,
            'has_legacy_artists' => $hasLegacyArtists,
            'has_venue_party' => $hasVenueParty,
            'has_invalid_management_payload' => $managementPayloadIssues !== [],
            'has_legacy_profile_group_members' => $hasLegacyProfileGroupMembers,
            'has_missing_occurrence_canonical_ownership' => $occurrenceCanonicalization['invalid'],
            'target_artist_ids' => $targetArtistIds,
            'canonical_artist_ids' => $canonicalArtistIds,
            'artist_parties_by_id' => $artistPartiesById,
            'occurrence_repairs' => $occurrenceCanonicalization['patches'],
        ];
    }

    /**
     * @param  array{
     *   invalid: bool,
     *   has_legacy_artists: bool,
     *   has_venue_party: bool,
     *   has_invalid_management_payload: bool,
     *   has_legacy_profile_group_members: bool,
     *   has_missing_occurrence_canonical_ownership: bool,
     *   target_artist_ids: array<int, string>,
     *   canonical_artist_ids: array<int, string>,
     *   artist_parties_by_id: array<string, array<string, mixed>>,
     *   occurrence_repairs: array<int, array{
     *     occurrence_id: string,
     *     own_event_parties: array<int, array<string, mixed>>,
     *     profile_groups: array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     *   }>
     * }  $analysis
     */
    private function repairEvent(Event $event, array $analysis): void
    {
        $this->profileGroupMemberStore->materializeLegacyIfNeeded($event, includeTrashedOccurrences: true);
        $occurrenceRepairs = is_array($analysis['occurrence_repairs'] ?? null)
            ? $analysis['occurrence_repairs']
            : [];

        $resolvedProfiles = $analysis['target_artist_ids'] === []
            ? []
            : $this->eventProfileResolver->resolveEventPartyProfilesByIds($analysis['target_artist_ids']);

        $existingParties = $this->normalizeArray($event->event_parties ?? []);
        $rebuiltParties = [];

        foreach ($existingParties as $party) {
            if (! is_array($party)) {
                continue;
            }

            $partyType = trim((string) ($party['party_type'] ?? ''));
            $partyRefId = trim((string) ($party['party_ref_id'] ?? ''));
            if ($partyType === 'venue' || in_array($partyRefId, $analysis['target_artist_ids'], true)) {
                continue;
            }

            $rebuiltParties[] = $party;
        }

        foreach ($resolvedProfiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileId = trim((string) ($profile['id'] ?? ''));
            $partyType = trim((string) ($profile['profile_type'] ?? ''));
            if ($profileId === '' || $partyType === '' || $partyType === 'venue') {
                throw new \RuntimeException('Legacy event party repair resolved an invalid account profile.');
            }

            $partyMapper = $this->eventPartyMappers->find($partyType);
            if ($partyMapper === null) {
                throw new \RuntimeException("Event party mapper [{$partyType}] is not registered.");
            }

            $existingParty = $analysis['artist_parties_by_id'][$profileId] ?? null;
            $canEdit = true;
            if (
                is_array($existingParty)
                && isset($existingParty['permissions'])
                && is_array($existingParty['permissions'])
                && array_key_exists('can_edit', $existingParty['permissions'])
            ) {
                $canEdit = (bool) $existingParty['permissions']['can_edit'];
            } else {
                $canEdit = $partyMapper->defaultCanEdit();
            }

            $rebuiltParties[] = [
                'party_type' => $partyType,
                'party_ref_id' => $profileId,
                'permissions' => [
                    'can_edit' => $canEdit,
                ],
                'metadata' => $partyMapper->mapMetadata($profile),
            ];
        }

        $didMutate = false;
        $normalizedEventParties = array_values($rebuiltParties);
        if (($event->event_parties ?? []) !== $normalizedEventParties) {
            $event->event_parties = $normalizedEventParties;
            $didMutate = true;
        }
        if ($event->artists !== null) {
            $event->artists = null;
            $didMutate = true;
        }
        $normalizedProfileGroups = $this->stripProfileGroupMembers($event->profile_groups ?? []);
        if (($event->profile_groups ?? []) !== $normalizedProfileGroups) {
            $event->profile_groups = $normalizedProfileGroups;
            $didMutate = true;
        }

        if ($this->canonicalizeManagementPayloadFields($event)) {
            $didMutate = true;
        }

        if ($didMutate) {
            $event->save();
        }

        $refreshed = Event::withTrashed()->find($event->getKey());
        if (! $refreshed instanceof Event) {
            throw new \RuntimeException('Legacy event party repair could not reload the updated event.');
        }

        if ($occurrenceRepairs !== []) {
            $this->repairOccurrenceCanonicalOwnership(
                $refreshed,
                $occurrenceRepairs,
            );

            return;
        }

        if ($didMutate) {
            $this->occurrenceReconciliationService->reconcileEvent($refreshed);

            $refreshed = Event::withTrashed()->find($event->getKey());
            if (! $refreshed instanceof Event) {
                throw new \RuntimeException('Legacy event party repair could not reload the reconciled event.');
            }
        }

        $occurrenceCanonicalization = $this->analyzeOccurrenceCanonicalization($refreshed);
        if ($occurrenceCanonicalization['invalid']) {
            $this->repairOccurrenceCanonicalOwnership(
                $refreshed,
                $occurrenceCanonicalization['patches'],
            );
        }
    }

    /**
     * @return array{
     *   invalid: bool,
     *   patches: array<int, array{
     *     occurrence_id: string,
     *     own_event_parties: array<int, array<string, mixed>>,
     *     profile_groups: array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     *   }>
     * }
     */
    private function analyzeOccurrenceCanonicalization(Event $event): array
    {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            return [
                'invalid' => false,
                'patches' => [],
            ];
        }

        $patches = [];

        foreach (
            EventOccurrence::withTrashed()
                ->where('event_id', $eventId)
                ->orderBy('starts_at')
                ->orderBy('_id')
                ->cursor() as $occurrence
        ) {
            $patch = $this->buildOccurrenceCanonicalPatch($event, $occurrence);
            if ($patch !== null) {
                $patches[] = $patch;
            }
        }

        return [
            'invalid' => $patches !== [],
            'patches' => $patches,
        ];
    }

    /**
     * @return array{
     *   occurrence_id: string,
     *   own_event_parties: array<int, array<string, mixed>>,
     *   profile_groups: array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     * }|null
     */
    private function buildOccurrenceCanonicalPatch(Event $event, EventOccurrence $occurrence): ?array
    {
        $eventId = trim((string) $event->getKey());
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($eventId === '' || $occurrenceId === '') {
            return null;
        }

        $existingOwnGroups = $this->canonicalizeProfileGroups(
            $this->profileGroupMemberStore->inflateGroupsWithMembers(
                $occurrence->own_profile_groups ?? [],
                'occurrence',
                $occurrenceId,
            ),
        );
        $occurrenceNestedGroups = $this->canonicalizeProfileGroups(
            $this->nestedAccountStore->legacyGroupsForOwner(
                $eventId,
                EventOccurrenceNestedAccountStore::PARENT_TYPE,
                $occurrenceId,
            ),
        );
        $legacyOccurrenceGroups = $this->canonicalizeProfileGroups(
            $this->profileGroupMemberStore->inflateGroupsWithMembers(
                $occurrence->profile_groups ?? [],
                'occurrence',
                $occurrenceId,
            ),
        );
        $eventNestedGroups = $this->canonicalizeProfileGroups(
            $this->nestedAccountStore->legacyGroupsForOwner(
                $eventId,
                'event',
                $eventId,
            ),
        );
        $legacyEventGroups = $this->canonicalizeProfileGroups(
            $this->profileGroupMemberStore->inflateGroupsWithMembers(
                $event->profile_groups ?? [],
                'event',
                $eventId,
            ),
        );
        $targetGroups = $this->mergeProfileGroups(
            $existingOwnGroups,
            $occurrenceNestedGroups,
            $legacyOccurrenceGroups,
            $eventNestedGroups,
            $legacyEventGroups,
        );

        $eventRootParties = $this->normalizeEventPartyRows($event->event_parties ?? []);
        $effectiveOccurrenceParties = $this->normalizeEventPartyRows(
            $occurrence->event_parties ?? [],
        );
        $existingOwnParties = $this->normalizeEventPartyRows(
            $occurrence->own_event_parties ?? [],
        );
        $partyRowsForTarget = $this->mergeEventPartyRows(
            $eventRootParties,
            $existingOwnParties,
            $effectiveOccurrenceParties,
        );

        $linkedProfiles = $this->mergeLinkedProfileSummaries(
            $occurrence->linked_account_profiles ?? [],
            $occurrence->own_linked_account_profiles ?? [],
            $event->linked_account_profiles ?? [],
            $event->own_linked_account_profiles ?? [],
            $this->linkedProfileSummariesForEventParties($partyRowsForTarget),
        );
        $assignedProfileIds = $this->orderedProfileIdsFromGroups($targetGroups);
        $unassignedLinkedProfiles = array_values(array_filter(
            $linkedProfiles,
            static fn (array $profile): bool => ! in_array(
                trim((string) ($profile['id'] ?? '')),
                $assignedProfileIds,
                true,
            ),
        ));
        if ($unassignedLinkedProfiles !== []) {
            $targetGroups = $this->mergeProfileGroups(
                $targetGroups,
                $this->fallbackProfileGroupsByPluralTypeLabel($unassignedLinkedProfiles),
            );
        }

        $targetGroups = $this->canonicalizeProfileGroups($targetGroups);
        if (! $this->groupsHaveMembers($targetGroups)) {
            return null;
        }

        $hasEmbeddedOwnGroupMembers = $this->groupsContainEmbeddedMembers(
            $occurrence->own_profile_groups ?? [],
        );
        $hasEmbeddedEffectiveGroupMembers = $this->groupsContainEmbeddedMembers(
            $occurrence->profile_groups ?? [],
        );

        $targetProfileIds = $this->orderedProfileIdsFromGroups($targetGroups);
        $targetOwnParties = $targetProfileIds === []
            ? []
            : $this->rebuildEventPartiesForProfileIds(
                $targetProfileIds,
                $this->mapEventPartiesByProfileId(
                    $partyRowsForTarget !== []
                        ? $partyRowsForTarget
                        : $existingOwnParties,
                ),
            );

        if (
            ! $hasEmbeddedOwnGroupMembers
            && ! $hasEmbeddedEffectiveGroupMembers
            &&
            $this->profileGroupsComparable($existingOwnGroups)
                === $this->profileGroupsComparable($targetGroups)
            && $this->eventPartiesComparable($existingOwnParties)
                === $this->eventPartiesComparable($targetOwnParties)
        ) {
            return null;
        }

        return [
            'occurrence_id' => $occurrenceId,
            'own_event_parties' => $targetOwnParties,
            'profile_groups' => $targetGroups,
        ];
    }

    /**
     * @param  array<int, array{
     *   occurrence_id: string,
     *   own_event_parties: array<int, array<string, mixed>>,
     *   profile_groups: array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     * }>  $patches
     */
    private function repairOccurrenceCanonicalOwnership(Event $event, array $patches): void
    {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '' || $patches === []) {
            return;
        }

        foreach ($patches as $patch) {
            $occurrenceId = trim((string) ($patch['occurrence_id'] ?? ''));
            if ($occurrenceId === '') {
                continue;
            }

            $occurrence = EventOccurrence::withTrashed()->find($occurrenceId);
            if (! $occurrence instanceof EventOccurrence) {
                throw new \RuntimeException(
                    "Legacy event occurrence repair could not load occurrence [{$occurrenceId}].",
                );
            }

            $profileGroups = $this->canonicalizeProfileGroups(
                $patch['profile_groups'] ?? [],
            );
            $profileGroupMetadata = array_values(array_map(
                static fn (array $group): array => [
                    'id' => $group['id'],
                    'label' => $group['label'],
                    'order' => $group['order'],
                ],
                $profileGroups,
            ));
            $ownEventParties = $this->normalizeEventPartyRows(
                $patch['own_event_parties'] ?? [],
            );

            $didMutate = false;
            if (
                $this->normalizeArray($occurrence->own_profile_groups ?? [])
                    !== $profileGroupMetadata
            ) {
                $occurrence->own_profile_groups = $profileGroupMetadata;
                $didMutate = true;
            }
            if (
                $this->eventPartiesComparable(
                    $this->normalizeEventPartyRows($occurrence->own_event_parties ?? []),
                ) !== $this->eventPartiesComparable($ownEventParties)
            ) {
                $occurrence->own_event_parties = $ownEventParties;
                $didMutate = true;
            }

            if ($didMutate) {
                $occurrence->save();
            }

            $this->profileGroupMemberStore->syncOccurrenceGroups(
                $eventId,
                $occurrence,
                $profileGroups,
            );
        }

        $refreshed = Event::withTrashed()->find($event->getKey());
        if (! $refreshed instanceof Event) {
            throw new \RuntimeException(
                'Legacy event occurrence repair could not reload the updated event.',
            );
        }

        $this->occurrenceReconciliationService->reconcileEvent($refreshed);
    }

    private function hasLegacyProfileGroupMembers(Event $event): bool
    {
        if ($this->groupsContainEmbeddedMembers($event->profile_groups ?? [])) {
            return true;
        }

        foreach (EventOccurrence::withTrashed()->where('event_id', (string) $event->getKey())->cursor() as $occurrence) {
            if ($this->groupsContainEmbeddedMembers($occurrence->own_profile_groups ?? [])) {
                return true;
            }
        }

        return false;
    }

    private function groupsContainEmbeddedMembers(mixed $rawGroups): bool
    {
        foreach ($this->normalizeArray($rawGroups) as $group) {
            $payload = $this->normalizeArray($group);
            $members = $this->normalizeArray($payload['account_profile_ids'] ?? $payload['profile_ids'] ?? []);
            if ($members !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stripProfileGroupMembers(mixed $rawGroups): array
    {
        $groups = [];

        foreach ($this->normalizeArray($rawGroups) as $index => $group) {
            $payload = $this->normalizeArray($group);
            $label = trim((string) ($payload['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = trim((string) ($payload['id'] ?? $payload['key'] ?? ''));
            if ($id === '') {
                $id = 'group-'.$index;
            }

            $groups[] = [
                'id' => $id,
                'label' => $label,
                'order' => isset($payload['order']) ? (int) $payload['order'] : $index,
            ];
        }

        return array_values($groups);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawGroups
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    private function canonicalizeProfileGroups(array $rawGroups): array
    {
        $groups = [];

        foreach (array_values($rawGroups) as $index => $group) {
            if (! is_array($group)) {
                continue;
            }

            $label = trim((string) ($group['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = trim((string) ($group['id'] ?? $group['key'] ?? ''));
            if ($id === '') {
                $id = Str::slug($label);
            }
            if ($id === '') {
                $id = 'group-'.$index;
            }

            $memberIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $memberId): string => trim((string) $memberId),
                $group['account_profile_ids'] ?? [],
            ), static fn (string $memberId): bool => $memberId !== '')));

            $groups[] = [
                'id' => $id,
                'label' => $label,
                'order' => isset($group['order']) ? (int) $group['order'] : $index,
                'account_profile_ids' => $memberIds,
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['label'], $left['id']]
                <=> [$right['order'], $right['label'], $right['id']],
        );

        return array_values($groups);
    }

    /**
     * @param  array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>  $groups
     */
    private function groupsHaveMembers(array $groups): bool
    {
        foreach ($groups as $group) {
            if (($group['account_profile_ids'] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>  $groups
     * @return array<int, string>
     */
    private function orderedProfileIdsFromGroups(array $groups): array
    {
        $profileIds = [];

        foreach ($groups as $group) {
            foreach (($group['account_profile_ids'] ?? []) as $profileId) {
                $profileId = trim((string) $profileId);
                if ($profileId === '' || in_array($profileId, $profileIds, true)) {
                    continue;
                }

                $profileIds[] = $profileId;
            }
        }

        return $profileIds;
    }

    /**
     * @param  array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}> ...$groupSets
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    private function mergeProfileGroups(array ...$groupSets): array
    {
        $merged = [];
        $indexByBucket = [];

        foreach ($groupSets as $groupSet) {
            foreach ($this->canonicalizeProfileGroups($groupSet) as $group) {
                $bucket = $this->groupBucketKey($group);
                if (! isset($indexByBucket[$bucket])) {
                    $indexByBucket[$bucket] = count($merged);
                    $merged[] = $group;

                    continue;
                }

                $index = $indexByBucket[$bucket];
                foreach ($group['account_profile_ids'] as $memberId) {
                    if (! in_array($memberId, $merged[$index]['account_profile_ids'], true)) {
                        $merged[$index]['account_profile_ids'][] = $memberId;
                    }
                }
            }
        }

        return $this->canonicalizeProfileGroups($merged);
    }

    /**
     * @param  array{id:string,label:string,order:int,account_profile_ids:array<int,string>}  $group
     */
    private function groupBucketKey(array $group): string
    {
        $label = trim((string) ($group['label'] ?? ''));
        if ($label !== '') {
            return Str::of($label)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();
        }

        return trim((string) ($group['id'] ?? ''));
    }

    /**
     * @param  array<int, array<string, mixed>>  $linkedProfiles
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    private function fallbackProfileGroupsByPluralTypeLabel(array $linkedProfiles): array
    {
        $labelsByType = $this->profileTypePluralLabelsByType($linkedProfiles);
        $groups = [];
        $indexByType = [];

        foreach ($this->normalizeLinkedProfileSummaries($linkedProfiles) as $profile) {
            $profileType = trim((string) ($profile['profile_type'] ?? ''));
            $profileId = trim((string) ($profile['id'] ?? ''));
            if ($profileType === '' || $profileId === '') {
                continue;
            }

            if (! isset($indexByType[$profileType])) {
                $indexByType[$profileType] = count($groups);
                $groups[] = [
                    'id' => $profileType,
                    'label' => $labelsByType[$profileType]
                        ?? Str::headline(Str::plural($profileType)),
                    'order' => count($groups),
                    'account_profile_ids' => [],
                ];
            }

            $groupIndex = $indexByType[$profileType];
            if (! in_array($profileId, $groups[$groupIndex]['account_profile_ids'], true)) {
                $groups[$groupIndex]['account_profile_ids'][] = $profileId;
            }
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['label'], $left['id']]
                <=> [$right['label'], $right['id']],
        );

        foreach ($groups as $index => &$group) {
            $group['order'] = $index;
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param  array<int, array<string, mixed>>  $linkedProfiles
     * @return array<string, string>
     */
    private function profileTypePluralLabelsByType(array $linkedProfiles): array
    {
        $types = [];

        foreach ($this->normalizeLinkedProfileSummaries($linkedProfiles) as $profile) {
            $type = trim((string) ($profile['profile_type'] ?? ''));
            if ($type !== '') {
                $types[$type] = true;
            }
        }

        if ($types === []) {
            return [];
        }

        return $this->eventProfileResolver->resolveProfileTypePluralLabelsByTypes(
            array_keys($types),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @return array<int, array{id:string,profile_type:string}>
     */
    private function normalizeLinkedProfileSummaries(mixed $profiles): array
    {
        $rows = $this->normalizeArray($profiles);
        if ($rows === []) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            $profile = $this->normalizeArray($row);
            $id = trim((string) ($profile['id'] ?? ''));
            $profileType = trim((string) ($profile['profile_type'] ?? ''));
            if ($id === '' || $profileType === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'profile_type' => $profileType,
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed ...$sources
     * @return array<int, array{id:string,profile_type:string}>
     */
    private function mergeLinkedProfileSummaries(mixed ...$sources): array
    {
        $merged = [];
        $profileIds = [];

        foreach ($sources as $source) {
            foreach ($this->normalizeLinkedProfileSummaries($source) as $profile) {
                $profileId = trim((string) ($profile['id'] ?? ''));
                if ($profileId === '' || isset($profileIds[$profileId])) {
                    continue;
                }

                $profileIds[$profileId] = true;
                $merged[] = $profile;
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array{id:string,profile_type:string}>
     */
    private function linkedProfileSummariesForEventParties(array $eventParties): array
    {
        $profileIds = [];

        foreach ($eventParties as $party) {
            if (! is_array($party)) {
                continue;
            }

            $profileId = trim((string) ($party['party_ref_id'] ?? ''));
            $profileType = trim((string) ($party['party_type'] ?? ''));
            if ($profileId === '' || $profileType === '' || $profileType === 'venue') {
                continue;
            }

            if (! in_array($profileId, $profileIds, true)) {
                $profileIds[] = $profileId;
            }
        }

        if ($profileIds === []) {
            return [];
        }

        $resolvedProfiles = $this->eventProfileResolver->resolveEventPartyProfilesByIds(
            $profileIds,
        );
        $summariesById = [];

        foreach ($resolvedProfiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileId = trim((string) ($profile['id'] ?? ''));
            $profileType = trim((string) ($profile['profile_type'] ?? ''));
            if ($profileId === '' || $profileType === '') {
                continue;
            }

            $summariesById[$profileId] = [
                'id' => $profileId,
                'profile_type' => $profileType,
            ];
        }

        $summaries = [];
        foreach ($profileIds as $profileId) {
            if (isset($summariesById[$profileId])) {
                $summaries[] = $summariesById[$profileId];
            }
        }

        return $summaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEventPartyRows(mixed $eventParties): array
    {
        return array_values(array_filter(
            $this->normalizeArray($eventParties),
            static fn (mixed $party): bool => is_array($party),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<string, array<string, mixed>>
     */
    private function mapEventPartiesByProfileId(array $eventParties): array
    {
        $mapped = [];

        foreach ($eventParties as $party) {
            if (! is_array($party)) {
                continue;
            }

            $profileId = trim((string) ($party['party_ref_id'] ?? ''));
            if ($profileId !== '') {
                $mapped[$profileId] = $party;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<int, array<string, mixed>> ...$partySets
     * @return array<int, array<string, mixed>>
     */
    private function mergeEventPartyRows(array ...$partySets): array
    {
        $merged = [];
        $indexById = [];

        foreach ($partySets as $partySet) {
            foreach ($this->normalizeEventPartyRows($partySet) as $party) {
                $profileId = trim((string) ($party['party_ref_id'] ?? ''));
                if ($profileId === '') {
                    continue;
                }

                if (! isset($indexById[$profileId])) {
                    $indexById[$profileId] = count($merged);
                    $merged[] = $party;

                    continue;
                }

                $merged[$indexById[$profileId]] = $party;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  array<string, array<string, mixed>>  $existingPartiesById
     * @return array<int, array<string, mixed>>
     */
    private function rebuildEventPartiesForProfileIds(
        array $profileIds,
        array $existingPartiesById,
    ): array {
        if ($profileIds === []) {
            return [];
        }

        $resolvedProfiles = $this->eventProfileResolver->resolveEventPartyProfilesByIds(
            $profileIds,
        );

        $resolvedById = [];
        foreach ($resolvedProfiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileId = trim((string) ($profile['id'] ?? ''));
            if ($profileId !== '') {
                $resolvedById[$profileId] = $profile;
            }
        }

        $rebuiltParties = [];
        foreach ($profileIds as $profileId) {
            $profile = $resolvedById[$profileId] ?? null;
            if (! is_array($profile)) {
                throw new \RuntimeException(
                    "Legacy event occurrence repair could not resolve account profile [{$profileId}].",
                );
            }

            $partyType = trim((string) ($profile['profile_type'] ?? ''));
            if ($partyType === '' || $partyType === 'venue') {
                throw new \RuntimeException(
                    "Legacy event occurrence repair resolved an invalid account profile [{$profileId}].",
                );
            }

            $partyMapper = $this->eventPartyMappers->find($partyType);
            if ($partyMapper === null) {
                throw new \RuntimeException(
                    "Event party mapper [{$partyType}] is not registered.",
                );
            }

            $existingParty = $existingPartiesById[$profileId] ?? null;
            $canEdit = true;
            if (
                is_array($existingParty)
                && isset($existingParty['permissions'])
                && is_array($existingParty['permissions'])
                && array_key_exists('can_edit', $existingParty['permissions'])
            ) {
                $canEdit = (bool) $existingParty['permissions']['can_edit'];
            } else {
                $canEdit = $partyMapper->defaultCanEdit();
            }

            $rebuiltParties[] = [
                'party_type' => $partyType,
                'party_ref_id' => $profileId,
                'permissions' => [
                    'can_edit' => $canEdit,
                ],
                'metadata' => $partyMapper->mapMetadata($profile),
            ];
        }

        return array_values($rebuiltParties);
    }

    /**
     * @param  array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function profileGroupsComparable(array $groups): array
    {
        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'account_profile_ids' => array_values($group['account_profile_ids'] ?? []),
            ],
            $groups,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventParties
     * @return array<int, array<string, mixed>>
     */
    private function eventPartiesComparable(array $eventParties): array
    {
        return array_values(array_map(
            static function (array $party): array {
                $metadata = isset($party['metadata']) && is_array($party['metadata'])
                    ? $party['metadata']
                    : [];
                $permissions = isset($party['permissions']) && is_array($party['permissions'])
                    ? $party['permissions']
                    : [];

                return [
                    'party_type' => trim((string) ($party['party_type'] ?? '')),
                    'party_ref_id' => trim((string) ($party['party_ref_id'] ?? '')),
                    'can_edit' => array_key_exists('can_edit', $permissions)
                        ? (bool) $permissions['can_edit']
                        : null,
                    'metadata' => [
                        'slug' => trim((string) ($metadata['slug'] ?? '')),
                        'display_name' => trim((string) ($metadata['display_name'] ?? '')),
                        'profile_type' => trim((string) ($metadata['profile_type'] ?? '')),
                    ],
                ];
            },
            $eventParties,
        ));
    }


    /**
     * @param  array<string, mixed>  $artist
     */
    private function resolveLegacyArtistId(array $artist): string
    {
        $rawId = $artist['id'] ?? $artist['_id'] ?? null;

        if (is_array($rawId)) {
            $legacyOid = trim((string) ($rawId['$oid'] ?? $rawId['oid'] ?? ''));
            if ($legacyOid !== '') {
                return $legacyOid;
            }
        }

        return trim((string) $rawId);
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function analyzeManagementPayloadContract(Event $event): array
    {
        $payload = $this->eventQueryService->formatManagementEvent($event);
        $issues = [];

        if (! $this->isNonEmptyScalar($payload['event_id'] ?? null)) {
            $issues[] = 'event_id';
        }
        if (! $this->isNonEmptyScalar($payload['slug'] ?? null)) {
            $issues[] = 'slug';
        }
        if (! $this->isNonEmptyScalar($payload['title'] ?? null)) {
            $issues[] = 'title';
        }

        $type = is_array($payload['type'] ?? null) ? $payload['type'] : null;
        if ($type === null) {
            $issues[] = 'type';
        } else {
            if (! $this->isNonEmptyScalar($type['name'] ?? null)) {
                $issues[] = 'type.name';
            }
            if (! $this->isNonEmptyScalar($type['slug'] ?? null)) {
                $issues[] = 'type.slug';
            }
        }

        $publication = is_array($payload['publication'] ?? null) ? $payload['publication'] : null;
        if ($publication === null || ! $this->isNonEmptyScalar($publication['status'] ?? null)) {
            $issues[] = 'publication.status';
        }

        $placeRef = $payload['place_ref'] ?? null;
        if ($placeRef !== null) {
            if (! is_array($placeRef)) {
                $issues[] = 'place_ref';
            } else {
                if (! $this->isNonEmptyScalar($placeRef['type'] ?? null)) {
                    $issues[] = 'place_ref.type';
                }
                if (! $this->isNonEmptyScalar($placeRef['id'] ?? null)) {
                    $issues[] = 'place_ref.id';
                }
            }
        }

        $rawThumb = $this->normalizeArray($event->thumb ?? null);
        if ($rawThumb !== []) {
            $rawThumbData = $this->normalizeArray($rawThumb['data'] ?? null);
            $rawThumbUrl = $rawThumbData['url'] ?? $rawThumb['url'] ?? $rawThumb['uri'] ?? null;
            if ($rawThumbUrl !== null && ! $this->isNullableAbsoluteUrl($rawThumbUrl)) {
                $issues[] = 'thumb.data.url';
            }
        }

        $thumb = is_array($payload['thumb'] ?? null) ? $payload['thumb'] : null;
        if ($thumb !== null) {
            $thumbData = is_array($thumb['data'] ?? null) ? $thumb['data'] : null;
            $thumbUrl = $thumbData['url'] ?? $thumb['url'] ?? null;
            if ($thumbUrl !== null && ! $this->isNullableAbsoluteUrl($thumbUrl)) {
                $issues[] = 'thumb.data.url';
            }
        }

        foreach (($payload['occurrences'] ?? []) as $index => $occurrence) {
            if (! is_array($occurrence) || ! $this->isNonEmptyScalar($occurrence['date_time_start'] ?? null)) {
                $issues[] = "occurrences.{$index}.date_time_start";
            }
        }

        return array_values(array_unique($issues));
    }

    private function canonicalizeManagementPayloadFields(Event $event): bool
    {
        $didMutate = false;

        $type = $this->normalizeArray($event->type ?? null);
        if ($type !== []) {
            $typeId = $this->resolveLegacyDocumentId($type);
            if ($typeId !== '' && trim((string) ($type['id'] ?? '')) === '') {
                $type['id'] = $typeId;
                $event->type = $type;
                $didMutate = true;
            }
        }

        $placeRef = $this->normalizeArray($event->place_ref ?? null);
        if ($placeRef !== []) {
            $placeRefId = $this->resolveLegacyDocumentId($placeRef);
            if ($placeRefId !== '' && trim((string) ($placeRef['id'] ?? '')) === '') {
                $placeRef['id'] = $placeRefId;
                $event->place_ref = $placeRef;
                $didMutate = true;
            }
        }

        $venue = $this->normalizeArray($event->venue ?? null);
        if ($venue !== []) {
            $venueId = $this->resolveLegacyDocumentId($venue);
            if ($venueId !== '' && trim((string) ($venue['id'] ?? '')) === '') {
                $venue['id'] = $venueId;
                $event->venue = $venue;
                $didMutate = true;
            }
        }

        $thumb = $this->normalizeArray($event->thumb ?? null);
        if ($thumb !== []) {
            $thumbData = $this->normalizeArray($thumb['data'] ?? null);
            $thumbUrl = $thumbData['url'] ?? $thumb['url'] ?? null;
            if ($thumbUrl !== null && ! $this->isNullableAbsoluteUrl($thumbUrl)) {
                $event->thumb = null;
                $didMutate = true;
            }
        }

        return $didMutate;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function resolveLegacyDocumentId(array $document): string
    {
        $rawId = $document['id'] ?? $document['_id'] ?? null;

        if (is_array($rawId)) {
            return trim((string) ($rawId['$oid'] ?? $rawId['oid'] ?? ''));
        }

        return trim((string) $rawId);
    }

    private function isNonEmptyScalar(mixed $value): bool
    {
        if (! $this->isNullableScalar($value)) {
            return false;
        }

        return trim((string) $value) !== '';
    }

    private function isNullableScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    private function isNullableAbsoluteUrl(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return true;
        }

        $parsed = parse_url($normalized);
        if (! is_array($parsed)) {
            return false;
        }

        $scheme = strtolower(trim((string) ($parsed['scheme'] ?? '')));
        $host = trim((string) ($parsed['host'] ?? ''));

        return ($scheme === 'http' || $scheme === 'https') && $host !== '';
    }
}
