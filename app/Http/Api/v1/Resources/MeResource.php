<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Resources;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Support\ValueObjects\SocialScoreDefaults;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;

final class MeResource
{
    /**
     * @return array<string, mixed>
     */
    public static function fromTenant(AccountUser $user): array
    {
        $tenant = Tenant::current();
        $userId = (string) $user->_id;
        $inviteMetrics = self::resolveInviteMetrics($userId);
        $socialScore = is_array($user->social_score) ? $user->social_score : SocialScoreDefaults::payload();
        $counters = is_array($user->counters)
            ? $user->counters
            : [
                'pending_invites' => 0,
                'confirmed_events' => 0,
                'favorites' => 0,
            ];

        if ($inviteMetrics instanceof PrincipalSocialMetric) {
            $socialScore['invites_accepted'] = (int) $inviteMetrics->credited_invite_acceptances;
            $counters['pending_invites'] = (int) $inviteMetrics->pending_invites_received;
        }

        return [
            'tenant_id' => $tenant ? (string) $tenant->_id : null,
            'data' => self::profilePayload(
                userId: $userId,
                displayName: $user->name ?? '',
                avatarUrl: null,
                userLevel: $user->user_level ?? 'basic',
                privacyMode: $user->privacy_mode ?? 'public',
                timezone: $user->timezone ?? null,
                socialScore: $socialScore,
                counters: $counters,
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
            'data' => self::profilePayload(
                userId: (string) $user->_id,
                displayName: $user->name ?? '',
                avatarUrl: null,
                userLevel: $user->user_level ?? 'basic',
                privacyMode: $user->privacy_mode ?? 'public',
                timezone: $user->timezone ?? null,
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
        ?string $timezone,
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
            'timezone' => $timezone,
            'social_score' => $socialScore,
            'counters' => $counters,
            'role_claims' => $roleClaims,
        ];
    }

    private static function resolveInviteMetrics(string $userId): ?PrincipalSocialMetric
    {
        /** @var PrincipalSocialMetric|null $metrics */
        $metrics = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', $userId)
            ->first();

        return $metrics;
    }
}
