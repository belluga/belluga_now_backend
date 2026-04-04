<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;

class AccountProfileAgendaOccurrencesService
{
    public function __construct(
        private readonly EventQueryService $eventQueryService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forProfile(AccountProfile $profile): array
    {
        $profileId = trim((string) $profile->getKey());
        if ($profileId === '') {
            return [];
        }

        $query = EventOccurrence::query()
            ->where('is_event_published', true)
            ->where('effective_ends_at', '>', Carbon::now());

        if ($this->isArtistProfile($profile)) {
            $query->where('artists.id', $profileId);
        } else {
            $query->where('place_ref.type', 'account_profile')
                ->where('place_ref.id', $profileId);
        }

        return $query
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->cursor()
            ->map(fn (EventOccurrence $occurrence): array => $this->eventQueryService->formatEvent($occurrence))
            ->values()
            ->all();
    }

    private function isArtistProfile(AccountProfile $profile): bool
    {
        return trim((string) ($profile->profile_type ?? '')) === 'artist';
    }
}
