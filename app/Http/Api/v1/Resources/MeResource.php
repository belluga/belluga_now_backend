<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Resources;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
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
        $personalProfile = self::resolvePersonalProfile($userId);
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
                accountProfileId: $personalProfile ? (string) $personalProfile->_id : null,
                displayName: self::displayName($user, $personalProfile),
                avatarUrl: self::avatarUrl($personalProfile),
                bio: self::plainBio($personalProfile),
                phone: self::primaryPhone($user),
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
                accountProfileId: null,
                displayName: $user->name ?? '',
                avatarUrl: null,
                bio: null,
                phone: null,
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
        ?string $accountProfileId,
        string $displayName,
        ?string $avatarUrl,
        ?string $bio,
        ?string $phone,
        string $userLevel,
        string $privacyMode,
        ?string $timezone,
        array $socialScore,
        array $counters,
        array $roleClaims
    ): array {
        return [
            'user_id' => $userId,
            'account_profile_id' => $accountProfileId,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'bio' => $bio,
            'phone' => $phone,
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

    private static function resolvePersonalProfile(string $userId): ?AccountProfile
    {
        if ($userId === '') {
            return null;
        }

        /** @var AccountProfile|null $profile */
        $profile = AccountProfile::query()
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->where('deleted_at', null)
            ->orderBy('_id')
            ->first();

        return $profile;
    }

    private static function displayName(AccountUser $user, ?AccountProfile $profile): string
    {
        $profileName = trim((string) ($profile?->display_name ?? ''));
        if ($profileName !== '') {
            return $profileName;
        }

        $userName = trim((string) ($user->name ?? ''));
        if ($userName === '') {
            return '';
        }

        $phone = self::primaryPhone($user);
        if (
            $phone !== null &&
            self::normalizePhoneComparable($userName) !== '' &&
            self::normalizePhoneComparable($userName) === self::normalizePhoneComparable($phone)
        ) {
            return '';
        }

        return $userName;
    }

    private static function avatarUrl(?AccountProfile $profile): ?string
    {
        if (! $profile instanceof AccountProfile) {
            return null;
        }

        return app(AccountProfileMediaService::class)->normalizePublicUrl(
            request()->getSchemeAndHttpHost(),
            $profile,
            'avatar',
            is_string($profile->avatar_url) ? $profile->avatar_url : null
        );
    }

    private static function plainBio(?AccountProfile $profile): ?string
    {
        if (! $profile instanceof AccountProfile || ! is_string($profile->bio)) {
            return null;
        }

        $plain = trim(strip_tags($profile->bio));

        return $plain === '' ? null : $plain;
    }

    private static function primaryPhone(AccountUser $user): ?string
    {
        $phones = is_array($user->phones ?? null) ? $user->phones : [];
        foreach ($phones as $phone) {
            $normalized = trim((string) $phone);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private static function normalizePhoneComparable(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
