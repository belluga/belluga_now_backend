<?php

declare(strict_types=1);

namespace Tests\Unit\Ticketing;

use App\Integration\Ticketing\CheckoutOrchestratorAdapter;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Mockery;
use Tests\TestCase;

class CheckoutOrchestratorAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPaidModeFailsFastWhenCheckoutIntegrationIsDisabled(): void
    {
        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('getNamespaceValue')
            ->with('tenant', 'checkout_ticketing')
            ->once()
            ->andReturn(['enabled' => false]);
        $store->shouldReceive('mergeNamespace')
            ->andReturnUsing(static fn (string $scope, string $namespace, array $changes, SettingsNamespaceDefinition $definition): array => $changes);

        $adapter = new CheckoutOrchestratorAdapter($store);
        $result = $adapter->beginCheckout(['checkout_mode' => 'paid'], 'idemp-1');

        $this->assertSame('integration_unavailable', $result['status']);
        $this->assertSame('paid_mode_deferred', $result['code']);
        $this->assertSame('idemp-1', $result['idempotency_key']);
    }

    public function testFreeModeIsAcceptedWithoutExternalCheckoutIntegration(): void
    {
        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('mergeNamespace')
            ->andReturnUsing(static fn (string $scope, string $namespace, array $changes, SettingsNamespaceDefinition $definition): array => $changes);

        $adapter = new CheckoutOrchestratorAdapter($store);
        $result = $adapter->beginCheckout(['checkout_mode' => 'free'], 'idemp-2');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('free', $result['mode']);
        $this->assertSame('idemp-2', $result['idempotency_key']);
    }

    public function testInvalidCheckoutModeIsRejected(): void
    {
        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('mergeNamespace')
            ->andReturnUsing(static fn (string $scope, string $namespace, array $changes, SettingsNamespaceDefinition $definition): array => $changes);

        $adapter = new CheckoutOrchestratorAdapter($store);
        $result = $adapter->beginCheckout(['checkout_mode' => 'unsupported'], 'idemp-3');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('invalid_checkout_mode', $result['code']);
        $this->assertSame('idemp-3', $result['idempotency_key']);
    }
}
