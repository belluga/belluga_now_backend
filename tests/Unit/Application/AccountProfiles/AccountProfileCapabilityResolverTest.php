<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityOverrideProviderContract;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolver;
use App\Application\AccountProfiles\Capabilities\HasGalleryCapability;
use App\Application\AccountProfiles\Capabilities\IsQueryableCapability;
use App\Application\AccountProfiles\Capabilities\LocationPolicyCapability;
use App\Application\AccountProfiles\Capabilities\MapPoiCapability;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AccountProfileCapabilityResolverTest extends TestCase
{
    public function test_resolves_typed_baseline_and_fails_closed_without_using_creation_defaults(): void
    {
        $registry = new AccountProfileCapabilityRegistry([
            new HasGalleryCapability,
            new LocationPolicyCapability,
        ], ['account-users:update']);
        $resolver = $this->resolver($registry, new class implements AccountProfileCapabilityOverrideProviderContract
        {
            public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
            {
                return null;
            }
        });

        $type = new TenantProfileType;
        $type->capabilities = [
            'has_gallery' => [
                'value' => true,
                'parameters' => ['max_groups' => 4, 'max_items_per_group' => 9],
            ],
            'location_policy' => ['value' => 'optional', 'parameters' => []],
        ];

        $gallery = $resolver->resolveForProfileType($type, 'has_gallery');
        $this->assertTrue($gallery['configured']['value']);
        $this->assertSame($gallery['configured'], $gallery['effective']);
        $this->assertSame(['max_groups' => 4, 'max_items_per_group' => 9], $gallery['effective']['parameters']);
        $this->assertSame('optional', $resolver->resolveForProfileType($type, 'location_policy')['effective']['value']);

        $type->capabilities = [
            'has_gallery' => ['value' => 'true', 'parameters' => ['max_groups' => -1]],
            'location_policy' => ['value' => 'unknown'],
        ];

        $this->assertSame(false, $resolver->resolveForProfileType($type, 'has_gallery')['effective']['value']);
        $this->assertSame(['max_groups' => 0, 'max_items_per_group' => 0], $resolver->resolveForProfileType($type, 'has_gallery')['effective']['parameters']);
        $this->assertSame('disabled', $resolver->resolveForProfileType($type, 'location_policy')['effective']['value']);
    }

    public function test_boolean_override_is_additive_and_invalid_contribution_is_rejected_atomically(): void
    {
        $registry = new AccountProfileCapabilityRegistry([new HasGalleryCapability], ['account-users:update']);
        $type = new TenantProfileType;
        $type->capabilities = [
            'has_gallery' => [
                'value' => false,
                'parameters' => ['max_groups' => 6, 'max_items_per_group' => 12],
            ],
        ];

        $activating = $this->resolver($registry, new class implements AccountProfileCapabilityOverrideProviderContract
        {
            public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
            {
                return ['value' => true, 'parameters' => ['max_groups' => 8]];
            }
        });
        $this->assertSame([
            'max_groups' => 8,
            'max_items_per_group' => 12,
        ], $activating->resolveForProfileType($type, 'has_gallery')['effective']['parameters']);
        $this->assertTrue($activating->resolveForProfileType($type, 'has_gallery')['effective']['value']);

        $invalid = $this->resolver($registry, new class implements AccountProfileCapabilityOverrideProviderContract
        {
            public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
            {
                return ['value' => false, 'parameters' => ['max_groups' => 99]];
            }
        });
        $resolved = $invalid->resolveForProfileType($type, 'has_gallery');
        $this->assertFalse($resolved['effective']['value']);
        $this->assertSame(6, $resolved['effective']['parameters']['max_groups']);
    }

    public function test_creation_defaults_are_distinct_from_runtime_fail_closed_values(): void
    {
        $registry = new AccountProfileCapabilityRegistry([
            new IsQueryableCapability,
            new HasGalleryCapability,
        ], ['account-users:update']);
        $resolver = $this->resolver(
            $registry,
            $this->nullOverrideProvider(),
        );
        $type = new TenantProfileType;
        $type->capabilities = [];

        $created = $resolver->materializeConfigurationForCreation();
        $this->assertTrue($created['is_queryable']['value']);
        $this->assertSame(6, $created['has_gallery']['parameters']['max_groups']);
        $this->assertFalse($resolver->resolveForProfileType($type, 'is_queryable')['effective']['value']);
        $this->assertSame(
            ['max_groups' => 0, 'max_items_per_group' => 0],
            $resolver->resolveForProfileType($type, 'has_gallery')['effective']['parameters'],
        );
    }

    public function test_malformed_parameters_and_unknown_override_keys_fail_closed_atomically(): void
    {
        $registry = new AccountProfileCapabilityRegistry([new HasGalleryCapability], ['account-users:update']);
        $type = new TenantProfileType;
        $type->capabilities = [
            'has_gallery' => [
                'value' => true,
                'parameters' => [
                    'max_groups' => '6',
                    'max_items_per_group' => -1,
                ],
            ],
        ];
        $resolver = $this->resolver($registry, new class implements AccountProfileCapabilityOverrideProviderContract
        {
            public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
            {
                return [
                    'value' => true,
                    'parameters' => ['unknown_parameter' => 99],
                ];
            }
        });

        $resolved = $resolver->resolveForProfileType($type, 'has_gallery');
        $this->assertTrue($resolved['effective']['value']);
        $this->assertSame(['max_groups' => 0, 'max_items_per_group' => 0], $resolved['effective']['parameters']);
    }

    public function test_loaded_profile_delegates_to_its_loaded_type_and_unknown_keys_are_rejected(): void
    {
        $registry = new AccountProfileCapabilityRegistry([new HasGalleryCapability], ['account-users:update']);
        $resolver = $this->resolver($registry, $this->nullOverrideProvider());
        $type = new TenantProfileType;
        $type->capabilities = [
            'has_gallery' => [
                'value' => true,
                'parameters' => ['max_groups' => 2, 'max_items_per_group' => 5],
            ],
        ];
        $profile = new AccountProfile;
        $profile->setRelation('profileType', $type);

        $this->assertSame(
            $resolver->resolveForProfileType($type, 'has_gallery'),
            $resolver->resolveForProfile($profile, 'has_gallery'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown account profile capability key [unknown].');
        $resolver->resolveForProfileType($type, 'unknown');
    }

    public function test_declared_dependency_preserves_configured_state_and_fails_closed_effectively(): void
    {
        $resolver = $this->resolver(new AccountProfileCapabilityRegistry([
            new LocationPolicyCapability,
            new MapPoiCapability,
        ], ['account-users:update']), $this->nullOverrideProvider());
        $type = new TenantProfileType;
        $type->capabilities = [
            'location_policy' => ['value' => 'disabled', 'parameters' => []],
            'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
        ];

        $resolved = $resolver->resolveForProfileType($type, 'is_map_poi_enabled');
        $this->assertTrue($resolved['configured']['value']);
        $this->assertFalse($resolved['effective']['value']);

        $type->capabilities = [
            'location_policy' => ['value' => 'optional', 'parameters' => []],
            'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
        ];
        $this->assertTrue($resolver->resolveForProfileType($type, 'is_map_poi_enabled')['effective']['value']);
    }

    private function resolver(
        AccountProfileCapabilityRegistry $registry,
        AccountProfileCapabilityOverrideProviderContract $overrides,
    ): AccountProfileCapabilityResolver {
        return new AccountProfileCapabilityResolver($registry, $overrides, new AccountProfileTypeIndexManifest);
    }

    private function nullOverrideProvider(): AccountProfileCapabilityOverrideProviderContract
    {
        return new class implements AccountProfileCapabilityOverrideProviderContract
        {
            public function contributionForProfileType(TenantProfileType $profileType, string $key): ?array
            {
                return null;
            }
        };
    }
}
