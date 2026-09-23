<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Validation\ValidationException;

final class AccountProfileLocationPolicy
{
    public function __construct(
        private readonly AccountProfileCapabilityResolverContract $capabilities,
    ) {}

    public function policyForType(TenantProfileType $profileType): string
    {
        return (string) $this->capabilities->resolveForProfileType($profileType, 'location_policy')['effective']['value'];
    }

    public function policyForTypeKey(string $profileType): string
    {
        $type = $this->findType($profileType);

        return $type instanceof TenantProfileType ? $this->policyForType($type) : 'disabled';
    }

    public function isLocationPermittedForType(TenantProfileType $profileType): bool
    {
        return $this->policyForType($profileType) !== 'disabled';
    }

    public function isMapProjectionEnabledForType(TenantProfileType $profileType): bool
    {
        return $this->isLocationPermittedForType($profileType)
            && $this->capabilities->resolveForProfileType($profileType, 'is_map_poi_enabled')['effective']['value'] === true;
    }

    public function isPhysicalHostEnabledForType(TenantProfileType $profileType): bool
    {
        return $this->isLocationPermittedForType($profileType)
            && $this->capabilities->resolveForProfileType($profileType, 'is_physical_host_enabled')['effective']['value'] === true;
    }

    public function isReferenceLocationEnabledForType(TenantProfileType $profileType): bool
    {
        return $this->isLocationPermittedForType($profileType)
            && $this->capabilities->resolveForProfileType($profileType, 'is_reference_location_enabled')['effective']['value'] === true;
    }

    public function isMapProjectionEligible(AccountProfile $profile): bool
    {
        $type = $this->findType((string) $profile->profile_type);

        return $type instanceof TenantProfileType
            && $this->isMapProjectionEnabledForType($type)
            && $this->hasValidPoint($profile->location ?? null);
    }

    public function assertCreateAllowed(string $profileType, mixed $location): void
    {
        $type = $this->findType($profileType);
        if (! $type instanceof TenantProfileType) {
            return;
        }

        $this->assertCreateAllowedForType($type, $location);
    }

    public function assertCreateAllowedForType(TenantProfileType $profileType, mixed $location): void
    {
        $this->assertLocationValueAllowed($this->policyForType($profileType), $location);
    }

    public function assertUpdateAllowed(
        AccountProfile $profile,
        string $nextProfileType,
        bool $locationWasSubmitted,
        mixed $submittedLocation,
    ): void {
        $type = $this->findType($nextProfileType);
        if (! $type instanceof TenantProfileType) {
            return;
        }

        $policy = $this->policyForType($type);
        if (! $locationWasSubmitted) {
            if ($policy === 'required' && ! $this->hasValidPoint($profile->location ?? null)) {
                $this->throwLocationValidation('Location is required for this profile type.');
            }

            return;
        }

        $this->assertLocationValueAllowed($policy, $submittedLocation);
    }

    public function assertUpdateAllowedForType(
        AccountProfile $profile,
        TenantProfileType $nextProfileType,
        bool $locationWasSubmitted,
        mixed $submittedLocation,
    ): void {
        $policy = $this->policyForType($nextProfileType);
        if (! $locationWasSubmitted) {
            if ($policy === 'required' && ! $this->hasValidPoint($profile->location ?? null)) {
                $this->throwLocationValidation('Location is required for this profile type.');
            }

            return;
        }

        $this->assertLocationValueAllowed($policy, $submittedLocation);
    }

    public function hasValidPoint(mixed $location): bool
    {
        if (! is_array($location)) {
            return false;
        }

        if (array_key_exists('lat', $location) || array_key_exists('lng', $location)) {
            return $this->validCoordinates($location['lng'] ?? null, $location['lat'] ?? null);
        }

        $coordinates = $location['coordinates'] ?? null;

        return ($location['type'] ?? null) === 'Point'
            && is_array($coordinates)
            && count($coordinates) === 2
            && $this->validCoordinates($coordinates[0] ?? null, $coordinates[1] ?? null);
    }

    /** @return array<string, mixed> */
    public function validPointMatchExpression(string $field = 'location'): array
    {
        $coordinates = "\${$field}.coordinates";

        return [
            "{$field}.type" => 'Point',
            "{$field}.coordinates.0" => ['$type' => 'number', '$gte' => -180, '$lte' => 180],
            "{$field}.coordinates.1" => ['$type' => 'number', '$gte' => -90, '$lte' => 90],
            '$expr' => ['$eq' => [[
                '$size' => ['$cond' => [
                    ['$isArray' => $coordinates],
                    $coordinates,
                    [],
                ]],
            ], 2]],
        ];
    }

    private function assertLocationValueAllowed(string $policy, mixed $location): void
    {
        if ($policy === 'disabled') {
            if ($location !== null) {
                $this->throwLocationValidation('Location is disabled for this profile type.');
            }

            return;
        }

        if ($location === null) {
            if ($policy === 'required') {
                $this->throwLocationValidation('Location is required for this profile type.');
            }

            return;
        }

        if (! $this->hasValidPoint($location)) {
            $this->throwLocationValidation('Location must contain one complete valid point.');
        }
    }

    private function validCoordinates(mixed $longitude, mixed $latitude): bool
    {
        return (is_int($longitude) || is_float($longitude))
            && (is_int($latitude) || is_float($latitude))
            && (float) $longitude >= -180.0
            && (float) $longitude <= 180.0
            && (float) $latitude >= -90.0
            && (float) $latitude <= 90.0;
    }

    private function findType(string $profileType): ?TenantProfileType
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return null;
        }

        return TenantProfileType::query()->where('type', $normalized)->first();
    }

    private function throwLocationValidation(string $message): never
    {
        throw ValidationException::withMessages([
            'location' => [$message],
            'location.lat' => [$message],
            'location.lng' => [$message],
        ]);
    }
}
