<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;

class AccountProfileAgendaOccurrencesService
{
    public function __construct(
        private readonly AccountProfileRegistryService $profileRegistryService,
        private readonly EventQueryService $eventQueryService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forProfile(AccountProfile $profile): array
    {
        $profileId = trim((string) $profile->getKey());
        if ($profileId === '' || ! $this->profileHasAgendaCapability($profile)) {
            return [];
        }

        $profileIdCandidates = $this->buildProfileIdCandidates($profileId);
        $query = EventOccurrence::query()
            ->where('is_event_published', true)
            ->where('effective_ends_at', '>', Carbon::now())
            ->where(function ($query) use ($profileIdCandidates): void {
                $query->where(function ($query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(function ($query) use ($profileIdCandidates): void {
                            $query->whereIn('place_ref.id', $profileIdCandidates)
                                ->orWhereIn('place_ref._id', $profileIdCandidates);
                        });
                })->orWhereRaw([
                    'event_parties' => [
                        '$elemMatch' => [
                            'party_ref_id' => ['$in' => $profileIdCandidates],
                        ],
                    ],
                ]);
            });

        return $query
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->cursor()
            ->map(fn (EventOccurrence $occurrence): array => $this->eventQueryService->formatEvent($occurrence))
            ->values()
            ->all();
    }

    private function profileHasAgendaCapability(AccountProfile $profile): bool
    {
        return $this->profileRegistryService->hasEvents(
            trim((string) ($profile->profile_type ?? ''))
        );
    }

    /**
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        if ($this->looksLikeObjectId($profileId)) {
            $candidates[] = new ObjectId($profileId);
        }

        return $candidates;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
