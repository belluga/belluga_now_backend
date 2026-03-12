<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;

class AccountProfileFormatterService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function format(AccountProfile $profile): array
    {
        $account = Account::query()->where('_id', $profile->account_id)->first();

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'avatar_url' => $profile->avatar_url,
            'cover_url' => $profile->cover_url,
            'bio' => $profile->bio,
            'content' => $profile->content,
            'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            'location' => $this->formatLocation($profile->location),
            'ownership_state' => $account
                ? $this->ownershipStateService->deriveOwnershipState($account)
                : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }
}
