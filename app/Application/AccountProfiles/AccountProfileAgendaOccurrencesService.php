<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Belluga\Events\Application\Events\EventOccurrenceNestedAccountStore;
use Belluga\Events\Application\Events\EventQueryService;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;

class AccountProfileAgendaOccurrencesService
{
    public function __construct(
        private readonly AccountProfileRegistryService $profileRegistryService,
        private readonly EventQueryService $eventQueryService,
        private readonly EventOccurrenceNestedAccountStore $occurrenceNestedAccountStore,
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

        $profileIdCandidates = $this->buildDocumentIdCandidates([$profileId]);
        $occurrenceIdCandidates = $this->buildDocumentIdCandidates(
            $this->occurrenceNestedAccountStore->occurrenceIdsForMemberProfiles([$profileId])
        );
        $query = EventOccurrence::query()
            ->where('is_event_published', true)
            ->where('effective_ends_at', '>', Carbon::now())
            ->where(function ($query) use ($profileIdCandidates, $occurrenceIdCandidates): void {
                $query->where(function ($query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(function ($query) use ($profileIdCandidates): void {
                            $query->whereIn('place_ref.id', $profileIdCandidates)
                                ->orWhereIn('place_ref._id', $profileIdCandidates);
                        });
                });

                if ($occurrenceIdCandidates !== []) {
                    $query->orWhereIn('_id', $occurrenceIdCandidates);
                }
            });

        $occurrences = $query
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->limit(InputConstraints::PUBLIC_PAGE_SIZE_MAX)
            ->get();

        return $this->eventQueryService->formatAgendaEvents($occurrences, null);
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
    private function buildDocumentIdCandidates(array $ids): array
    {
        $candidates = [];

        foreach ($ids as $id) {
            $normalizedId = trim((string) $id);
            if ($normalizedId === '') {
                continue;
            }

            $candidates[] = $normalizedId;

            if ($this->looksLikeObjectId($normalizedId)) {
                $candidates[] = new ObjectId($normalizedId);
            }
        }

        return $candidates;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
