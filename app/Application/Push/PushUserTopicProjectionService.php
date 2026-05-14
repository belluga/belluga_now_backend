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
            $this->allUsersTopics(),
            $this->favoriteProfileTopicsForUserId($userId),
            $this->confirmedEventTopicsForUserId($userId),
        ), static fn (string $topic): bool => trim($topic) !== '')));
    }

    /**
     * @return array<int, string>
     */
    public function allUsersTopics(): array
    {
        $topic = $this->naming->allUsersTopic();

        return $topic === '' ? [] : [$topic];
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

    public function userHasFavoriteAccountProfile(string $userId, string $accountProfileId): bool
    {
        $userId = trim($userId);
        $accountProfileId = trim($accountProfileId);
        if ($userId === '' || $accountProfileId === '') {
            return false;
        }

        return FavoriteEdge::query()
            ->where('owner_user_id', $userId)
            ->where('registry_key', 'account_profile')
            ->where('target_type', 'account_profile')
            ->where('target_id', $accountProfileId)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function confirmedEventTopicsForUserId(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }

        return collect($this->attendance->confirmedEventIds($userId))
            ->map(fn (string $eventId): string => $this->naming->confirmedEventTopic($eventId))
            ->filter(static fn (string $topic): bool => trim($topic) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function userHasConfirmedEvent(string $userId, string $eventId): bool
    {
        return $this->attendance->hasConfirmedEvent($userId, $eventId);
    }
}
