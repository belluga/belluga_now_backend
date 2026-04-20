<?php

declare(strict_types=1);

namespace App\Application\ProximityPreferences;

use App\Models\Tenants\ProximityPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProximityPreferenceOwnershipService
{
    public function __construct(
        private readonly ProximityPreferenceService $preferenceService,
    ) {}

    /**
     * @param  iterable<string>  $sourceUserIds
     */
    public function mergeOwnership(string $targetUserId, iterable $sourceUserIds): void
    {
        $normalizedTargetUserId = trim($targetUserId);
        if ($normalizedTargetUserId === '') {
            return;
        }

        $normalizedSourceIds = Collection::make($sourceUserIds)
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '' && $id !== $normalizedTargetUserId)
            ->unique()
            ->values();

        if ($normalizedSourceIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, ProximityPreference> $preferences */
        $preferences = ProximityPreference::query()
            ->whereIn('owner_user_id', [$normalizedTargetUserId, ...$normalizedSourceIds->all()])
            ->get();

        if ($preferences->isEmpty()) {
            return;
        }

        $winner = $this->resolveWinner($preferences, $normalizedTargetUserId);
        $currentTarget = $preferences->firstWhere(
            'owner_user_id',
            $normalizedTargetUserId,
        );

        if (! $currentTarget instanceof ProximityPreference ||
            ! $this->samePayload($currentTarget, $winner)
        ) {
            ProximityPreference::query()->updateOrCreate(
                ['owner_user_id' => $normalizedTargetUserId],
                $this->preferenceService->toPayload($winner),
            );
        }

        ProximityPreference::query()
            ->whereIn('owner_user_id', $normalizedSourceIds->all())
            ->delete();
    }

    /**
     * @param  Collection<int, ProximityPreference>  $preferences
     */
    private function resolveWinner(Collection $preferences, string $targetUserId): ProximityPreference
    {
        /** @var array<int, ProximityPreference> $ordered */
        $ordered = $preferences->all();

        usort($ordered, function (ProximityPreference $left, ProximityPreference $right) use ($targetUserId): int {
            $leftUpdatedAt = $this->timestamp($left->updated_at);
            $rightUpdatedAt = $this->timestamp($right->updated_at);

            if ($leftUpdatedAt !== $rightUpdatedAt) {
                return $rightUpdatedAt <=> $leftUpdatedAt;
            }

            $leftIsTarget = (string) $left->owner_user_id === $targetUserId;
            $rightIsTarget = (string) $right->owner_user_id === $targetUserId;
            if ($leftIsTarget !== $rightIsTarget) {
                return $rightIsTarget <=> $leftIsTarget;
            }

            return strcmp((string) $right->getKey(), (string) $left->getKey());
        });

        return $ordered[0];
    }

    private function samePayload(ProximityPreference $left, ProximityPreference $right): bool
    {
        return $this->preferenceService->toPayload($left) ===
            $this->preferenceService->toPayload($right);
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return 0;
    }
}
