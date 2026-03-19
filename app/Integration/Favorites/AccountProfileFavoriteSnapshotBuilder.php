<?php

declare(strict_types=1);

namespace App\Integration\Favorites;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Contracts\FavoriteSnapshotBuilderContract;
use Belluga\Favorites\Support\FavoriteRegistryDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AccountProfileFavoriteSnapshotBuilder implements FavoriteSnapshotBuilderContract
{
    public function build(string $targetId, FavoriteRegistryDefinition $definition): ?array
    {
        $profile = AccountProfile::withTrashed()->where('_id', $targetId)->first();
        if (! $profile || $profile->trashed() || (bool) ($profile->is_active ?? true) === false) {
            return null;
        }

        $now = Carbon::now();

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

        $nextOccurrenceId = $nextOccurrence ? (string) $nextOccurrence->getAttribute('_id') : null;
        $nextOccurrenceAt = $nextOccurrence?->starts_at;
        $lastOccurrenceAt = $lastOccurrence?->starts_at;
        $slug = $profile->slug ? (string) $profile->slug : null;

        return [
            'target' => [
                'id' => (string) $profile->getAttribute('_id'),
                'slug' => $slug ?? '',
                'display_name' => (string) ($profile->display_name ?? ''),
                'avatar_url' => $profile->avatar_url ?? null,
            ],
            'snapshot' => [
                'next_event_occurrence_id' => $nextOccurrenceId,
                'next_event_occurrence_at' => $nextOccurrenceAt,
                'last_event_occurrence_at' => $lastOccurrenceAt,
            ],
            'next_event_occurrence_id' => $nextOccurrenceId,
            'next_event_occurrence_at' => $nextOccurrenceAt,
            'last_event_occurrence_at' => $lastOccurrenceAt,
            'navigation' => [
                'kind' => 'account_profile',
                'target_slug' => $slug,
            ],
        ];
    }

    private function baseOccurrenceQuery(string $profileId): Builder
    {
        return EventOccurrence::query()
            ->where('deleted_at', null)
            ->where('is_event_published', true)
            ->where(static function (Builder $query) use ($profileId): void {
                $query->where('venue.id', $profileId)
                    ->orWhere('artists.id', $profileId);
            });
    }
}
