<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Application\Events\AttendanceCommitmentService;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;

class PushUserTopicProjectionService
{
    public function __construct(
        private readonly PushChannelNamingService $naming,
        private readonly AttendanceCommitmentService $attendance,
    ) {}

    /**
     * @return array<int, string>
     */
    public function topicsForUserId(string $userId): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->favoriteProfileTopicsForUserId($userId),
            $this->confirmedOccurrenceTopicsForUserId($userId),
        ), static fn (string $topic): bool => trim($topic) !== '')));
    }

    /**
     * @return array<int, string>
     */
    public function favoriteProfileTopicsForUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        return FavoriteEdge::query()
            ->where('owner_user_id', $userId)
            ->where('registry_key', 'account_profile')
            ->where('target_type', 'account_profile')
            ->pluck('target_id')
            ->map(fn (mixed $targetId): string => $this->naming->favoriteAccountProfileTopic((string) $targetId))
            ->filter(static fn (string $topic): bool => trim($topic) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function confirmedOccurrenceTopicsForUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        return collect($this->attendance->confirmedOccurrenceIds($userId))
            ->map(fn (string $occurrenceId): string => $this->naming->confirmedOccurrenceTopic($occurrenceId))
            ->filter(static fn (string $topic): bool => trim($topic) !== '')
            ->unique()
            ->values()
            ->all();
    }
}
