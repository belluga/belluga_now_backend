<?php

declare(strict_types=1);

namespace App\Integration\Invites;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Belluga\Invites\Contracts\InviteRecipientProfileProjectionContract;

final class InviteRecipientProfileProjectionAdapter implements InviteRecipientProfileProjectionContract
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
    ) {}

    public function profilesByIds(array $accountProfileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $accountProfileIds,
        ))));

        if ($ids === []) {
            return [];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', $ids)
            ->get();
        $ownerIds = $profiles
            ->map(static fn (AccountProfile $profile): string => trim((string) ($profile->created_by ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $usersById = $ownerIds === []
            ? []
            : AccountUser::query()
                ->whereIn('_id', $ownerIds)
                ->get()
                ->keyBy(static fn (AccountUser $user): string => (string) $user->_id)
                ->all();

        $baseUrl = request()->getSchemeAndHttpHost();
        $result = [];
        foreach ($profiles as $profile) {
            $profileId = (string) $profile->_id;
            $ownerId = trim((string) ($profile->created_by ?? ''));
            $owner = $usersById[$ownerId] ?? null;

            $result[$profileId] = [
                'receiver_account_profile_id' => $profileId,
                'receiver_user_id' => $owner instanceof AccountUser ? (string) $owner->_id : null,
                'display_name' => $this->displayName($profile, $owner),
                'avatar_url' => $this->mediaService->normalizePublicUrl(
                    $baseUrl,
                    $profile,
                    'avatar',
                    is_string($profile->avatar_url) ? $profile->avatar_url : null,
                ),
            ];
        }

        return $result;
    }

    private function displayName(AccountProfile $profile, ?AccountUser $user): ?string
    {
        return $this->nullableString($profile->display_name)
            ?? $this->nullableString($user?->name)
            ?? $this->nullableString($profile->slug);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
