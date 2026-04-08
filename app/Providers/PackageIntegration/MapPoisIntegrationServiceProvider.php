<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\MapPois\MapPoiRegistryAdapter;
use App\Integration\MapPois\MapPoiSettingsAdapter;
use App\Integration\MapPois\MapPoiSourceReaderAdapter;
use App\Integration\MapPois\MapPoiTenantContextAdapter;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\ServiceProvider;

class MapPoisIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            MapPoiSourceReaderContract::class,
            MapPoiSourceReaderAdapter::class
        );

        $this->app->bind(
            MapPoiRegistryContract::class,
            MapPoiRegistryAdapter::class
        );

        $this->app->bind(
            MapPoiSettingsContract::class,
            MapPoiSettingsAdapter::class
        );

        $this->app->bind(
            MapPoiTenantContextContract::class,
            MapPoiTenantContextAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        $ability = 'map-pois-settings:update';

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'map_ui',
            scope: 'tenant',
            label: 'Map UI',
            groupLabel: 'Core',
            ability: $ability,
            fields: [
                'radius.min_km' => ['type' => 'number', 'nullable' => false, 'label' => 'Radius Min (KM)', 'default' => 0.5, 'order' => 10],
                'radius.default_km' => ['type' => 'number', 'nullable' => false, 'label' => 'Radius Default (KM)', 'default' => 5, 'order' => 20],
                'radius.max_km' => ['type' => 'number', 'nullable' => false, 'label' => 'Radius Max (KM)', 'default' => 50, 'order' => 30],
                'poi_time_window_days.past' => ['type' => 'integer', 'nullable' => false, 'label' => 'POI Past Window (days)', 'default' => 0, 'order' => 40],
                'poi_time_window_days.future' => ['type' => 'integer', 'nullable' => false, 'label' => 'POI Future Window (days)', 'default' => 0, 'order' => 50],
                'default_origin.lat' => ['type' => 'number', 'nullable' => true, 'label' => 'Default Origin Latitude', 'default' => null, 'order' => 60],
                'default_origin.lng' => ['type' => 'number', 'nullable' => true, 'label' => 'Default Origin Longitude', 'default' => null, 'order' => 70],
                'default_origin.label' => ['type' => 'string', 'nullable' => true, 'label' => 'Default Origin Label', 'default' => null, 'order' => 80],
                'filters' => [
                    'type' => 'array',
                    'nullable' => false,
                    'label' => 'Map Filters',
                    'label_i18n_key' => 'settings.map_ui.filters.label',
                    'group' => 'filters',
                    'group_label' => 'Filters',
                    'group_label_i18n_key' => 'settings.map_ui.group.filters.label',
                    'default' => [],
                    'order' => 90,
                ],
            ],
            order: 10,
            labelI18nKey: 'settings.map_ui.namespace.label',
            description: 'Map and POI defaults.',
            descriptionI18nKey: 'settings.map_ui.namespace.description',
            icon: 'map',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'map_ingest',
            scope: 'tenant',
            label: 'Map Ingest',
            groupLabel: 'Core',
            ability: $ability,
            fields: [
                'rebuild.enabled' => ['type' => 'boolean', 'nullable' => false, 'label' => 'Allow Rebuild', 'default' => true, 'order' => 10],
                'rebuild.batch_size' => ['type' => 'integer', 'nullable' => false, 'label' => 'Rebuild Batch Size', 'default' => 200, 'order' => 20],
            ],
            order: 20,
            labelI18nKey: 'settings.map_ingest.namespace.label',
            description: 'Projection ingest and rebuild controls.',
            descriptionI18nKey: 'settings.map_ingest.namespace.description',
            icon: 'sync',
        ));

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'map_security',
            scope: 'tenant',
            label: 'Map Security',
            groupLabel: 'Core',
            ability: $ability,
            fields: [
                'allow_public_nearby' => ['type' => 'boolean', 'nullable' => false, 'label' => 'Allow Nearby Query', 'default' => true, 'order' => 10],
            ],
            order: 30,
            labelI18nKey: 'settings.map_security.namespace.label',
            description: 'Map query policy controls.',
            descriptionI18nKey: 'settings.map_security.namespace.description',
            icon: 'shield',
        ));
    }
}
