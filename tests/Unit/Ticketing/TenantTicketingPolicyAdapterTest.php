<?php

declare(strict_types=1);

namespace Tests\Unit\Ticketing;

use App\Integration\Ticketing\TenantTicketingPolicyAdapter;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Mockery;
use Tests\TestCase;

class TenantTicketingPolicyAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_resolves_enabled_and_identity_mode_from_settings_kernel(): void
    {
        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('getNamespaceValue')
            ->with('tenant', 'ticketing_core')
            ->twice()
            ->andReturn([
                'enabled' => true,
                'identity_mode' => 'guest_or_auth',
            ]);

        // Unused in adapter but required by contract.
        $store->shouldReceive('mergeNamespace')
            ->andReturnUsing(static fn (string $scope, string $namespace, array $changes, SettingsNamespaceDefinition $definition): array => $changes);

        $adapter = new TenantTicketingPolicyAdapter($store);

        $this->assertTrue($adapter->isTicketingEnabled());
        $this->assertSame('guest_or_auth', $adapter->identityMode());
    }

    public function test_it_falls_back_to_secure_defaults_when_values_are_missing_or_invalid(): void
    {
        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('getNamespaceValue')
            ->with('tenant', 'ticketing_core')
            ->twice()
            ->andReturn([
                'identity_mode' => 'invalid_mode',
            ]);

        // Unused in adapter but required by contract.
        $store->shouldReceive('mergeNamespace')
            ->andReturnUsing(static fn (string $scope, string $namespace, array $changes, SettingsNamespaceDefinition $definition): array => $changes);

        $adapter = new TenantTicketingPolicyAdapter($store);

        $this->assertFalse($adapter->isTicketingEnabled());
        $this->assertSame('auth_only', $adapter->identityMode());
    }
}
