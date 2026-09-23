<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class PhysicalHostEligibilityPolicy
{
    public function __construct(
        private readonly AccountProfileLocationPolicy $locationPolicy,
    ) {}

    public function isEligible(AccountProfile $profile): bool
    {
        $type = TenantProfileType::query()
            ->where('type', trim((string) $profile->profile_type))
            ->first();

        return $type instanceof TenantProfileType
            && $this->isEligibleForTypeAndLocation($type, $profile->location ?? null);
    }

    public function isEligibleForTypeAndLocation(TenantProfileType $profileType, mixed $location): bool
    {
        return $this->locationPolicy->isPhysicalHostEnabledForType($profileType)
            && $this->locationPolicy->hasValidPoint($location);
    }

    /**
     * @param  Builder<AccountProfile>  $query
     * @return Builder<AccountProfile>
     */
    public function applyEligibleLocationConstraint(Builder $query): Builder
    {
        return $query->whereRaw($this->locationPolicy->validPointMatchExpression());
    }

    /**
     * @param  array<int, string>  $eligibleProfileTypes
     */
    public function assertEligibleFromTypeSet(AccountProfile $profile, array $eligibleProfileTypes): array
    {
        $profileType = trim((string) ($profile->profile_type ?? ''));
        if (! in_array($profileType, $eligibleProfileTypes, true)
            || ! $this->locationPolicy->hasValidPoint($profile->location ?? null)) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Account profile is not eligible as a physical host.'],
            ]);
        }

        /** @var array<string, mixed> $location */
        $location = $profile->location;

        return $location;
    }

    /** @return array<string, mixed> */
    public function assertEligible(AccountProfile $profile): array
    {
        if (! $this->isEligible($profile)) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Account profile is not eligible as a physical host.'],
            ]);
        }

        /** @var array<string, mixed> $location */
        $location = $profile->location;

        return $location;
    }
}
