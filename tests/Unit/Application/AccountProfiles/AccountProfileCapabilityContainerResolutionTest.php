<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityOverrideProviderContract;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use Tests\TestCase;

final class AccountProfileCapabilityContainerResolutionTest extends TestCase
{
    public function test_container_exposes_one_canonical_resolver_with_internal_registry_and_override_collaborators(): void
    {
        $this->assertInstanceOf(AccountProfileCapabilityRegistry::class, app(AccountProfileCapabilityRegistry::class));
        $this->assertInstanceOf(AccountProfileCapabilityResolverContract::class, app(AccountProfileCapabilityResolverContract::class));
        $this->assertInstanceOf(AccountProfileCapabilityOverrideProviderContract::class, app(AccountProfileCapabilityOverrideProviderContract::class));
        $resolver = app(AccountProfileCapabilityResolverContract::class);
        $this->assertTrue(method_exists($resolver, 'materializeConfigurationForCreation'));
        $this->assertTrue(method_exists($resolver, 'repairConfiguration'));
        $this->assertTrue(method_exists($resolver, 'definitionForPersistence'));
        $this->assertTrue(method_exists($resolver, 'resolveAllForProfileType'));
        $this->assertTrue(method_exists($resolver, 'typeIdsWhereAllEffectiveValues'));
    }
}
