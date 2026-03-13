<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Settings\TenantScopeContextAdapter;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\ServiceProvider;

class SettingsIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TenantScopeContextContract::class,
            TenantScopeContextAdapter::class
        );
    }

    public function boot(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'telemetry',
            scope: 'tenant',
            label: 'Telemetry',
            groupLabel: 'Core',
            ability: 'telemetry-settings:update',
            fields: [
                'location_freshness_minutes' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Location Freshness (minutes)',
                    'label_i18n_key' => 'settings.telemetry.location_freshness_minutes.label',
                    'default' => (int) config('telemetry.location_freshness_minutes', 5),
                    'order' => 10,
                ],
                'trackers' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Trackers',
                    'label_i18n_key' => 'settings.telemetry.trackers.label',
                    'default' => [],
                    'order' => 20,
                ],
            ],
            order: 30,
            labelI18nKey: 'settings.telemetry.namespace.label',
            description: 'Telemetry tracker configuration for analytics/event sinks.',
            descriptionI18nKey: 'settings.telemetry.namespace.description',
            icon: 'analytics',
        ));
    }
}
