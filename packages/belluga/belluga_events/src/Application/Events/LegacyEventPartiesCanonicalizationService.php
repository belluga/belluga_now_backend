<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Support\Facades\Log;

class LegacyEventPartiesCanonicalizationService
{
    public function __construct(
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventPartyMapperRegistryContract $eventPartyMappers,
        private readonly EventOccurrenceReconciliationService $occurrenceReconciliationService,
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

        Event::query()
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
     *   target_artist_ids: array<int, string>,
     *   canonical_artist_ids: array<int, string>,
     *   artist_parties_by_id: array<string, array<string, mixed>>
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

        foreach ($legacyArtists as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            $artistId = trim((string) ($artist['id'] ?? ''));
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
            || $targetArtistIds !== $canonicalArtistIds;

        return [
            'invalid' => $invalid,
            'has_legacy_artists' => $hasLegacyArtists,
            'has_venue_party' => $hasVenueParty,
            'target_artist_ids' => $targetArtistIds,
            'canonical_artist_ids' => $canonicalArtistIds,
            'artist_parties_by_id' => $artistPartiesById,
        ];
    }

    /**
     * @param  array{
     *   invalid: bool,
     *   has_legacy_artists: bool,
     *   has_venue_party: bool,
     *   target_artist_ids: array<int, string>,
     *   canonical_artist_ids: array<int, string>,
     *   artist_parties_by_id: array<string, array<string, mixed>>
     * }  $analysis
     */
    private function repairEvent(Event $event, array $analysis): void
    {
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

        $event->event_parties = array_values($rebuiltParties);
        $event->artists = null;
        $event->save();

        $this->occurrenceReconciliationService->reconcileEvent($event->fresh());
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
}
