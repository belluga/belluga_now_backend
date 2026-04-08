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
        private readonly EventQueryService $eventQueryService,
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
        $managementPayloadIssues = $this->analyzeManagementPayloadContract($event);

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
            || $targetArtistIds !== $canonicalArtistIds
            || $managementPayloadIssues !== [];

        return [
            'invalid' => $invalid,
            'has_legacy_artists' => $hasLegacyArtists,
            'has_venue_party' => $hasVenueParty,
            'has_invalid_management_payload' => $managementPayloadIssues !== [],
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
     *   has_invalid_management_payload: bool,
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

        $this->occurrenceReconciliationService->reconcileEvent($refreshed);
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
