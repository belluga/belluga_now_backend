<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use App\Application\Taxonomies\TaxonomyValidationService;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\Event;
use App\Jobs\MapPois\DeleteMapPoiByRefJob;
use App\Jobs\MapPois\UpsertMapPoiFromEventJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventManagementService
{
    public function __construct(
        private readonly TaxonomyValidationService $taxonomyValidationService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Event
    {
        $normalized = $this->normalizePayload($payload, null);

        $event = Event::create($normalized)->fresh();
        UpsertMapPoiFromEventJob::dispatch((string) $event->_id);

        return $event;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(Event $event, array $payload): Event
    {
        $normalized = $this->normalizePayload($payload, $event);

        $event->fill($normalized);
        $event->save();

        $event = $event->fresh();
        UpsertMapPoiFromEventJob::dispatch((string) $event->_id);

        return $event;
    }

    public function delete(Event $event): void
    {
        $event->delete();
        DeleteMapPoiByRefJob::dispatch('event', (string) $event->_id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, ?Event $existing): array
    {
        $normalized = [];

        foreach ([
            'title',
            'content',
            'type',
            'thumb',
            'tags',
            'categories',
            'taxonomy_terms',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = $payload[$field];
            }
        }

        if (array_key_exists('taxonomy_terms', $payload)) {
            $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForEvent($taxonomyTerms);
            }
        }

        if (array_key_exists('date_time_start', $payload)) {
            $normalized['date_time_start'] = $payload['date_time_start'];
        }

        if (array_key_exists('date_time_end', $payload)) {
            $normalized['date_time_end'] = $payload['date_time_end'];
        }

        $publication = $payload['publication'] ?? null;
        if ($publication !== null || $existing === null) {
            $normalized['publication'] = $this->resolvePublicationPayload($publication, $existing);
        }

        $venueId = $payload['venue_id'] ?? null;
        $artistIds = $payload['artist_ids'] ?? null;

        if ($venueId !== null || $existing === null) {
            $resolvedVenue = $this->resolveVenuePayload($venueId, $existing);
            $normalized['venue'] = $resolvedVenue['venue'];
            $normalized['geo_location'] = $resolvedVenue['location'];
            $normalized['account_id'] = $resolvedVenue['account_id'];
            $normalized['account_profile_id'] = $resolvedVenue['account_profile_id'];

            $expectedAccountId = $payload['account_id'] ?? null;
            if ($expectedAccountId && $expectedAccountId !== $resolvedVenue['account_id']) {
                throw ValidationException::withMessages([
                    'account_id' => ['Account must match the venue account.'],
                ]);
            }

            $expectedProfileId = $payload['account_profile_id'] ?? null;
            if ($expectedProfileId && $expectedProfileId !== $resolvedVenue['account_profile_id']) {
                throw ValidationException::withMessages([
                    'account_profile_id' => ['Account profile must match the venue profile.'],
                ]);
            }
        }

        if ($artistIds !== null || $existing === null) {
            $normalized['artists'] = $this->resolveArtistPayloads($artistIds, $existing);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $publication
     * @return array<string, mixed>
     */
    private function resolvePublicationPayload(?array $publication, ?Event $existing): array
    {
        $current = $existing?->publication ?? [];
        $current = is_array($current) ? $current : [];

        $status = $publication['status'] ?? $current['status'] ?? 'draft';
        $publishAt = $publication['publish_at'] ?? $current['publish_at'] ?? null;

        if (! in_array($status, ['published', 'publish_scheduled', 'draft', 'ended'], true)) {
            throw ValidationException::withMessages([
                'publication.status' => ['Invalid publication status.'],
            ]);
        }

        $publishAt = $this->normalizePublishAt($publishAt);

        if ($status === 'publish_scheduled' && ! $publishAt) {
            throw ValidationException::withMessages([
                'publication.publish_at' => ['publish_at is required for publish_scheduled status.'],
            ]);
        }

        if ($status === 'published' && ! $publishAt) {
            $publishAt = Carbon::now();
        }

        return [
            'status' => $status,
            'publish_at' => $publishAt,
        ];
    }

    /**
     * @return array{venue: array<string, mixed>, location: array<string, mixed>, account_id: string, account_profile_id: string}
     */
    private function resolveVenuePayload(?string $venueId, ?Event $existing): array
    {
        $id = $venueId ?? ($existing?->venue['id'] ?? null);

        if (! $id) {
            throw ValidationException::withMessages([
                'venue_id' => ['Venue account profile is required.'],
            ]);
        }

        $profile = AccountProfile::query()->where('_id', $id)->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'venue_id' => ['Venue account profile not found.'],
            ]);
        }

        $location = $profile->location ?? null;
        if (! is_array($location) || ! isset($location['type'], $location['coordinates'])) {
            throw ValidationException::withMessages([
                'venue_id' => ['Venue account profile must include a location.'],
            ]);
        }
        if (! is_array($location['coordinates']) || count($location['coordinates']) < 2) {
            throw ValidationException::withMessages([
                'venue_id' => ['Venue account profile must include valid coordinates.'],
            ]);
        }

        return [
            'account_id' => (string) $profile->account_id,
            'account_profile_id' => (string) $profile->_id,
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

    /**
     * @param array<int, string>|null $artistIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveArtistPayloads(?array $artistIds, ?Event $existing): array
    {
        $ids = $artistIds;
        if ($ids === null) {
            $current = $existing?->artists ?? [];
            $current = is_array($current) ? $current : [];
            return $current;
        }

        if ($ids === []) {
            return [];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', array_values($ids))
            ->get();

        $missing = array_diff($ids, $profiles->pluck('_id')->map(fn ($id) => (string) $id)->all());
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'artist_ids' => ['Some artists were not found.'],
            ]);
        }

        $invalid = $profiles->filter(fn (AccountProfile $profile): bool => $profile->profile_type !== 'artist');
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'artist_ids' => ['All artists must be account profiles of type artist.'],
            ]);
        }

        return $profiles->map(function (AccountProfile $profile): array {
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

    private function normalizePublishAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
