<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Illuminate\Validation\ValidationException;

class AccountProfileResolverAdapter implements EventProfileResolverContract
{
    public function resolveVenueByProfileId(string $profileId): array
    {
        $profile = AccountProfile::query()->where('_id', $profileId)->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Venue account profile not found.'],
            ]);
        }

        $location = $profile->location ?? null;
        if (! is_array($location) || ! isset($location['type'], $location['coordinates'])) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Venue account profile must include a location.'],
            ]);
        }
        if (! is_array($location['coordinates']) || count($location['coordinates']) < 2) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Venue account profile must include valid coordinates.'],
            ]);
        }

        return [
            'venue' => [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'tagline' => null,
                'hero_image_url' => $profile->cover_url ?? null,
                'logo_url' => $profile->avatar_url ?? null,
                'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            ],
            'location' => $location,
        ];
    }

    public function resolveArtistsByProfileIds(array $artistProfileIds): array
    {
        if ($artistProfileIds === []) {
            return [];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', array_values($artistProfileIds))
            ->get();

        $resolvedIds = $profiles->pluck('_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $missing = array_diff($artistProfileIds, $resolvedIds);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'artist_ids' => ['Some artists were not found.'],
            ]);
        }

        $invalid = $profiles->filter(
            static fn (AccountProfile $profile): bool => $profile->profile_type !== 'artist'
        );
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'artist_ids' => ['All artists must be account profiles of type artist.'],
            ]);
        }

        return $profiles->map(static function (AccountProfile $profile): array {
            $taxonomy = $profile->taxonomy_terms ?? [];
            $genres = [];

            if (is_array($taxonomy)) {
                foreach ($taxonomy as $term) {
                    if (! is_array($term)) {
                        continue;
                    }

                    $type = $term['type'] ?? '';
                    if (in_array($type, ['music_genre', 'genre'], true)) {
                        $genres[] = (string) ($term['value'] ?? '');
                    }
                }
            }

            return [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'avatar_url' => $profile->avatar_url ?? null,
                'highlight' => false,
                'genres' => array_values(array_filter($genres, static fn ($item): bool => $item !== '')),
                'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            ];
        })->all();
    }

    public function listProfileIdsForAccount(string $accountId): array
    {
        return AccountProfile::query()
            ->where('account_id', $accountId)
            ->get()
            ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    public function accountOwnsProfile(string $accountId, string $profileId): bool
    {
        return AccountProfile::query()
            ->where('_id', $profileId)
            ->where('account_id', $accountId)
            ->exists();
    }
}
