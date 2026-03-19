<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Integration\Settings\TenantScopeContextAdapter;
use Belluga\Settings\Contracts\SettingsMergePolicyContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsSchemaValidatorContract;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Belluga\Settings\Validation\ConditionExpressionEvaluator;
use Tests\TestCase;

class SettingsPackageBindingsTest extends TestCase
{
    public function test_settings_kernel_contracts_are_bound(): void
    {
        $this->assertInstanceOf(SettingsRegistryContract::class, $this->app->make(SettingsRegistryContract::class));
        $this->assertInstanceOf(SettingsStoreContract::class, $this->app->make(SettingsStoreContract::class));
        $this->assertInstanceOf(SettingsSchemaValidatorContract::class, $this->app->make(SettingsSchemaValidatorContract::class));
        $this->assertInstanceOf(SettingsMergePolicyContract::class, $this->app->make(SettingsMergePolicyContract::class));
        $this->assertInstanceOf(TenantScopeContextAdapter::class, $this->app->make(TenantScopeContextContract::class));
        $this->assertInstanceOf(ConditionExpressionEvaluator::class, $this->app->make(ConditionExpressionEvaluator::class));
    }

    public function test_core_and_push_namespaces_are_registered(): void
    {
        $registry = $this->app->make(SettingsRegistryContract::class);

        $this->assertNotNull($registry->find('map_ui', 'tenant'));
        $this->assertNotNull($registry->find('events', 'tenant'));
        $this->assertNotNull($registry->find('push', 'tenant'));
        $this->assertNotNull($registry->find('firebase', 'tenant'));
        $this->assertNotNull($registry->find('telemetry', 'tenant'));
        $this->assertNotNull($registry->find('app_links', 'tenant'));
    }
}
