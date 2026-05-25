<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\AccountProfileHeroImageResolverContract;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class EventHeroImageResolver
{
    public function __construct(
        private readonly AccountProfileHeroImageResolverContract $accountProfileHeroImages,
    ) {}

    /**
     * Resolve the canonical event hero image used by downstream event consumers.
     *
     * Order: event thumb, linked account profiles, then venue/location media.
     *
     * @param  array<string, mixed>  $eventPayload
     */
    public function resolveFromPayload(array $eventPayload): ?string
    {
        $thumb = $this->normalizeArray($eventPayload['thumb'] ?? []);
        $thumbData = $this->normalizeArray($thumb['data'] ?? []);
        $venue = $this->normalizeArray($eventPayload['venue'] ?? []);

        return $this->firstPresentUrl([
            $thumbData['url'] ?? null,
            $thumb['url'] ?? null,
            $thumb['uri'] ?? null,
            ...$this->linkedAccountProfileHeroImageCandidates($eventPayload),
            $venue['cover_url'] ?? null,
            $venue['hero_image_url'] ?? null,
            $venue['avatar_url'] ?? null,
            $venue['logo_url'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $eventPayload
     * @return array<int, mixed>
     */
    private function linkedAccountProfileHeroImageCandidates(array $eventPayload): array
    {
        $linkedProfiles = $this->normalizeArray($eventPayload['linked_account_profiles'] ?? []);
        if ($linkedProfiles === []) {
            $linkedProfiles = $this->linkedProfilesFromEventParties($eventPayload['event_parties'] ?? []);
        }

        $candidates = [];
        foreach ($linkedProfiles as $profile) {
            $profilePayload = $this->normalizeArray($profile);
            if ($profilePayload === []) {
                continue;
            }

            $candidates[] = $this->accountProfileHeroImages->resolveFromPayload(
                $profilePayload,
                allowTypeVisualFallback: false
            );
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linkedProfilesFromEventParties(mixed $eventParties): array
    {
        $profiles = [];
        foreach ($this->normalizeArray($eventParties) as $party) {
            $partyPayload = $this->normalizeArray($party);
            if (trim((string) ($partyPayload['party_type'] ?? '')) === 'venue') {
                continue;
            }

            $metadata = $this->normalizeArray($partyPayload['metadata'] ?? []);
            if ($metadata === []) {
                continue;
            }

            $profiles[] = [
                'cover_url' => $metadata['cover_url'] ?? null,
                'avatar_url' => $metadata['avatar_url'] ?? null,
            ];
        }

        return $profiles;
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstPresentUrl(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
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
