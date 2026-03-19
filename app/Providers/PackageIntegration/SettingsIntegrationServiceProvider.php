<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Settings\TenantAppLinksPatchGuard;
use App\Integration\Settings\TenantScopeContextAdapter;
use Belluga\Settings\Contracts\SettingsNamespacePatchGuardContract;
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
        $this->app->singleton(
            SettingsNamespacePatchGuardContract::class,
            TenantAppLinksPatchGuard::class,
        );
    }

    public function boot(): void
    {
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

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'app_links',
            scope: 'tenant',
            label: 'App Links',
            groupLabel: 'Mobile',
            ability: 'push-settings:update',
            fields: [
                'android.sha256_cert_fingerprints' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Android SHA-256 Fingerprints',
                    'label_i18n_key' => 'settings.app_links.android.sha256_cert_fingerprints.label',
                    'default' => [],
                    'order' => 10,
                ],
                'ios.team_id' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'Apple Team ID',
                    'label_i18n_key' => 'settings.app_links.ios.team_id.label',
                    'default' => null,
                    'order' => 20,
                ],
                'ios.paths' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'iOS Universal Link Paths',
                    'label_i18n_key' => 'settings.app_links.ios.paths.label',
                    'default' => ['/invite*', '/convites*'],
                    'order' => 30,
                ],
            ],
            order: 40,
            labelI18nKey: 'settings.app_links.namespace.label',
            description: 'Per-tenant Android App Links and iOS Universal Links credentials. App identifiers are managed via typed app domains.',
            descriptionI18nKey: 'settings.app_links.namespace.description',
            icon: 'link',
        ));
    }
}
