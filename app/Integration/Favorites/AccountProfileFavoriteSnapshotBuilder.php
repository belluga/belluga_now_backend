<?php

declare(strict_types=1);

namespace App\Integration\Favorites;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Contracts\FavoriteSnapshotBuilderContract;
use Belluga\Favorites\Support\FavoriteRegistryDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;

class AccountProfileFavoriteSnapshotBuilder implements FavoriteSnapshotBuilderContract
{
    public function __construct(
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    public function build(string $targetId, FavoriteRegistryDefinition $definition): ?array
    {
        $profile = AccountProfile::withTrashed()->where('_id', $targetId)->first();
        if (! $profile || $profile->trashed() || (bool) ($profile->is_active ?? true) === false) {
            return null;
        }

        $now = Carbon::now();

        $liveNowOccurrence = $this->baseOccurrenceQuery($targetId)
            ->where('starts_at', '<=', $now)
            ->where('effective_ends_at', '>', $now)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->first();

        $nextOccurrence = $this->baseOccurrenceQuery($targetId)
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->first();

        $lastOccurrence = $this->baseOccurrenceQuery($targetId)
            ->where('starts_at', '<', $now)
            ->orderBy('starts_at', 'desc')
            ->orderBy('_id', 'desc')
            ->first();

        $liveNowOccurrenceId = $liveNowOccurrence ? (string) $liveNowOccurrence->getAttribute('_id') : null;
        $liveNowOccurrenceAt = $liveNowOccurrence?->starts_at;
        $nextOccurrenceId = $nextOccurrence ? (string) $nextOccurrence->getAttribute('_id') : null;
        $nextOccurrenceAt = $nextOccurrence?->starts_at;
        $lastOccurrenceAt = $lastOccurrence?->starts_at;
        $eventNavigationOccurrence = $liveNowOccurrence ?? $nextOccurrence;
        $eventTargetOccurrenceId = $eventNavigationOccurrence
            ? (string) $eventNavigationOccurrence->getAttribute('_id')
            : null;
        $eventTargetSlug = $eventNavigationOccurrence?->slug
            ? trim((string) $eventNavigationOccurrence->slug)
            : null;
        $slug = $profile->slug ? (string) $profile->slug : null;
        $normalizedSlug = trim((string) $slug);
        $canOpenPublicDetail = $normalizedSlug !== ''
            && $this->typeSetProvider->isPubliclyNavigable((string) $profile->profile_type);
        $publicDetailPath = $canOpenPublicDetail ? '/parceiro/'.$normalizedSlug : null;
        $eventTargetPath = $this->buildEventTargetPath(
            $eventTargetSlug,
            $eventTargetOccurrenceId,
        );

        return [
            'target' => [
                'id' => (string) $profile->getAttribute('_id'),
                'slug' => $slug ?? '',
                'display_name' => (string) ($profile->display_name ?? ''),
                'avatar_url' => $profile->avatar_url ?? null,
                'cover_url' => $profile->cover_url ?? null,
                'profile_type' => $profile->profile_type ? (string) $profile->profile_type : null,
                'can_open_public_detail' => $canOpenPublicDetail,
                'public_detail_path' => $publicDetailPath,
            ],
            'snapshot' => [
                'live_now_event_occurrence_id' => $liveNowOccurrenceId,
                'live_now_event_occurrence_at' => $liveNowOccurrenceAt,
                'next_event_occurrence_id' => $nextOccurrenceId,
                'next_event_occurrence_at' => $nextOccurrenceAt,
                'last_event_occurrence_at' => $lastOccurrenceAt,
            ],
            'live_now_event_occurrence_id' => $liveNowOccurrenceId,
            'live_now_event_occurrence_at' => $liveNowOccurrenceAt,
            'next_event_occurrence_id' => $nextOccurrenceId,
            'next_event_occurrence_at' => $nextOccurrenceAt,
            'last_event_occurrence_at' => $lastOccurrenceAt,
            'navigation' => [
                'kind' => $eventTargetPath !== null ? 'event' : 'account_profile',
                'target_slug' => $eventTargetPath !== null ? $eventTargetSlug : $slug,
                'target_path' => $eventTargetPath ?? $publicDetailPath,
                'profile_target_path' => $publicDetailPath,
                'event_target_path' => $eventTargetPath,
                'event_target_slug' => $eventTargetSlug,
                'event_occurrence_id' => $eventTargetOccurrenceId,
                'can_open_public_detail' => $canOpenPublicDetail,
            ],
        ];
    }

    private function buildEventTargetPath(?string $eventSlug, ?string $occurrenceId): ?string
    {
        $normalizedSlug = trim((string) $eventSlug);
        $normalizedOccurrenceId = trim((string) $occurrenceId);

        if ($normalizedSlug === '' || $normalizedOccurrenceId === '') {
            return null;
        }

        return '/agenda/evento/'.$normalizedSlug.'?occurrence='.rawurlencode($normalizedOccurrenceId);
    }

    private function baseOccurrenceQuery(string $profileId): Builder
    {
        $profileIdCandidates = $this->buildProfileIdCandidates($profileId);

        return EventOccurrence::query()
            ->where('deleted_at', null)
            ->where('is_event_published', true)
            ->where(static function (Builder $query) use ($profileIdCandidates): void {
                $query->where(static function (Builder $query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(static function (Builder $query) use ($profileIdCandidates): void {
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
