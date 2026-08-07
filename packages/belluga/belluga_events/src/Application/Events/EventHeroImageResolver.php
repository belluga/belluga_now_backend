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
     * Order: event thumb, counterpart preview, then venue/location media.
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
            ...$this->counterpartPreviewHeroImageCandidates($eventPayload),
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
    private function counterpartPreviewHeroImageCandidates(array $eventPayload): array
    {
        $counterpartPreview = $this->normalizeArray($eventPayload['counterpart_preview'] ?? []);

        $candidates = [];
        foreach ($counterpartPreview as $profile) {
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
