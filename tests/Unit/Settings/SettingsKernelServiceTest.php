<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsSchemaValidatorContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Mockery;
use Tests\TestCase;

class SettingsKernelServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_values_materialize_schema_defaults_for_missing_fields(): void
    {
        $definition = $this->pushDefinition();

        $registry = Mockery::mock(SettingsRegistryContract::class);
        $registry->shouldReceive('all')
            ->with('tenant')
            ->once()
            ->andReturn([$definition]);

        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('getNamespaceValue')
            ->with('tenant', 'push')
            ->once()
            ->andReturn([]);

        $validator = Mockery::mock(SettingsSchemaValidatorContract::class);

        $service = new SettingsKernelService($registry, $store, $validator);

        $values = $service->values('tenant', null);

        $this->assertSame(7, $values['push']['max_ttl_days']);
        $this->assertSame([], $values['push']['message_routes']);
        $this->assertSame([], $values['push']['message_types']);
    }

    public function test_patch_namespace_returns_defaults_even_when_store_only_persists_changes(): void
    {
        $definition = $this->pushDefinition();

        $registry = Mockery::mock(SettingsRegistryContract::class);
        $registry->shouldReceive('find')
            ->with('push', 'tenant')
            ->once()
            ->andReturn($definition);

        $store = Mockery::mock(SettingsStoreContract::class);
        $store->shouldReceive('mergeNamespace')
            ->with('tenant', 'push', ['throttles' => null], $definition)
            ->once()
            ->andReturn([
                'throttles' => null,
            ]);

        $validator = Mockery::mock(SettingsSchemaValidatorContract::class);
        $validator->shouldReceive('validatePatch')
            ->with($definition, ['throttles' => null])
            ->once()
            ->andReturn([
                'throttles' => null,
            ]);

        $service = new SettingsKernelService($registry, $store, $validator);

        $values = $service->patchNamespace('tenant', null, 'push', [
            'throttles' => null,
        ]);

        $this->assertSame(7, $values['max_ttl_days']);
        $this->assertNull($values['throttles']);
        $this->assertSame([], $values['message_routes']);
        $this->assertSame([], $values['message_types']);
    }

    private function pushDefinition(): SettingsNamespaceDefinition
    {
        return new SettingsNamespaceDefinition(
            namespace: 'push',
            scope: 'tenant',
            label: 'Push',
            groupLabel: 'Notifications',
            ability: null,
            fields: [
                'throttles' => [
                    'type' => 'object',
                    'nullable' => true,
                    'label' => 'Throttles',
                ],
                'max_ttl_days' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Max TTL Days',
                    'default' => 7,
                ],
                'message_routes' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Message Routes',
                    'default' => [],
                ],
                'message_types' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Message Types',
                    'default' => [],
                ],
            ],
        );
    }
}
