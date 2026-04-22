<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\DiscoveryFilters\TenantDiscoveryFilterSettingsAdapter;
use App\Integration\DiscoveryFilters\AccountProfileDiscoveryFilterEntityProvider;
use App\Integration\DiscoveryFilters\EventDiscoveryFilterEntityProvider;
use App\Integration\DiscoveryFilters\StaticAssetDiscoveryFilterEntityProvider;
use Belluga\DiscoveryFilters\Contracts\DiscoveryFilterSettingsContract;
use Belluga\DiscoveryFilters\Registry\DiscoveryFilterEntityRegistry;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\ServiceProvider;

final class DiscoveryFiltersIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            DiscoveryFilterSettingsContract::class,
            TenantDiscoveryFilterSettingsAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);

        /** @var DiscoveryFilterEntityRegistry $entityRegistry */
        $entityRegistry = $this->app->make(DiscoveryFilterEntityRegistry::class);
        $entityRegistry->register($this->app->make(EventDiscoveryFilterEntityProvider::class));
        $entityRegistry->register($this->app->make(AccountProfileDiscoveryFilterEntityProvider::class));
        $entityRegistry->register($this->app->make(StaticAssetDiscoveryFilterEntityProvider::class));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'discovery_filters',
            scope: 'tenant',
            label: 'Discovery Filters',
            groupLabel: 'Core',
            ability: 'discovery-filters-settings:update',
            fields: [
                'surfaces' => [
                    'type' => 'mixed',
                    'nullable' => false,
                    'label' => 'Surface filters',
                    'default' => [],
                    'order' => 10,
                ],
            ],
            order: 35,
            labelI18nKey: 'settings.discovery_filters.namespace.label',
            description: 'Tenant-authored public discovery filters by surface.',
            descriptionI18nKey: 'settings.discovery_filters.namespace.description',
            icon: 'filter_alt',
        ));
    }
}
