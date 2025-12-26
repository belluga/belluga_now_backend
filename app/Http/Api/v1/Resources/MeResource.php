<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Resources;

use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use App\Models\Landlord\Tenant;
use App\Support\ValueObjects\SocialScoreDefaults;

final class MeResource
{
    /**
     * @return array<string, mixed>
     */
    public static function fromTenant(AccountUser $user): array
    {
        $tenant = Tenant::current();

        return [
            'tenant_id' => $tenant ? (string) $tenant->_id : null,
            'data' => static::profilePayload(
                userId: (string) $user->_id,
                displayName: $user->name ?? '',
                avatarUrl: null,
                userLevel: $user->user_level ?? 'basic',
                privacyMode: $user->privacy_mode ?? 'public',
                socialScore: $user->social_score ?? SocialScoreDefaults::payload(),
                counters: $user->counters ?? [
                    'pending_invites' => 0,
                    'confirmed_events' => 0,
                    'favorites' => 0,
                ],
                roleClaims: $user->role_claims ?? [
                    'is_partner' => false,
                    'is_curator' => false,
                    'is_verified' => false,
                ]
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromLandlord(LandlordUser $user): array
    {
        return [
            'tenant_id' => null,
            'data' => static::profilePayload(
                userId: (string) $user->_id,
                displayName: $user->name ?? '',
                avatarUrl: null,
                userLevel: $user->user_level ?? 'basic',
                privacyMode: $user->privacy_mode ?? 'public',
                socialScore: $user->social_score ?? SocialScoreDefaults::payload(),
                counters: $user->counters ?? [
                    'pending_invites' => 0,
                    'confirmed_events' => 0,
                    'favorites' => 0,
                ],
                roleClaims: $user->role_claims ?? [
                    'is_partner' => false,
                    'is_curator' => false,
                    'is_verified' => false,
                ]
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function profilePayload(
        string $userId,
        string $displayName,
        ?string $avatarUrl,
        string $userLevel,
        string $privacyMode,
        array $socialScore,
        array $counters,
        array $roleClaims
    ): array {
        return [
            'user_id' => $userId,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'user_level' => $userLevel,
            'privacy_mode' => $privacyMode,
            'social_score' => $socialScore,
            'counters' => $counters,
            'role_claims' => $roleClaims,
        ];
    }
}
