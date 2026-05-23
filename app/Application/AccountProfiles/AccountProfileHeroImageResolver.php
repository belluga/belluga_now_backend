<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Belluga\Events\Contracts\AccountProfileHeroImageResolverContract;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileHeroImageResolver implements AccountProfileHeroImageResolverContract
{
    /**
     * Resolve account-profile hero imagery from an already-loaded payload.
     *
     * Order: cover, avatar, then optional image-backed type visual.
     *
     * @param  array<string, mixed>  $profilePayload
     */
    public function resolveFromPayload(array $profilePayload, bool $allowTypeVisualFallback = false): ?string
    {
        $visual = $this->normalizeArray($profilePayload['visual'] ?? []);

        $candidates = [
            $profilePayload['cover_url'] ?? null,
            $profilePayload['avatar_url'] ?? null,
        ];

        if ($allowTypeVisualFallback) {
            $candidates[] = $visual['image_url'] ?? null;
            $candidates[] = $profilePayload['type_asset_url'] ?? null;
        }

        return $this->firstPresentUrl($candidates);
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
