<?php

declare(strict_types=1);

namespace Belluga\MapPois;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Application\MapPoiQueryService;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Console\Commands\RebuildMapPoisCommand;
use Belluga\MapPois\Integration\Events\MapPoiEventAsyncJobSignaturesAdapter;
use Belluga\MapPois\Integration\Events\MapPoiEventProjectionSyncAdapter;
use Belluga\MapPois\Listeners\EventsPackage\SyncMapPoiOnEventCreated;
use Belluga\MapPois\Listeners\EventsPackage\SyncMapPoiOnEventDeleted;
use Belluga\MapPois\Listeners\EventsPackage\SyncMapPoiOnEventUpdated;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class MapPoisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MapPoiProjectionService::class);
        $this->app->singleton(MapPoiQueryService::class);

        $this->ensureHostBinding(MapPoiSourceReaderContract::class);
        $this->ensureHostBinding(MapPoiRegistryContract::class);
        $this->ensureHostBinding(MapPoiSettingsContract::class);
        $this->ensureHostBinding(MapPoiTenantContextContract::class);

        if (interface_exists(EventProjectionSyncContract::class)) {
            $this->app->bind(EventProjectionSyncContract::class, MapPoiEventProjectionSyncAdapter::class);
        }

        if (interface_exists(EventAsyncJobSignaturesContract::class)) {
            $this->app->bind(EventAsyncJobSignaturesContract::class, MapPoiEventAsyncJobSignaturesAdapter::class);
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/map_pois.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildMapPoisCommand::class,
            ]);
        }

        if (class_exists(EventCreated::class)) {
            Event::listen(EventCreated::class, SyncMapPoiOnEventCreated::class);
            Event::listen(EventUpdated::class, SyncMapPoiOnEventUpdated::class);
            Event::listen(EventDeleted::class, SyncMapPoiOnEventDeleted::class);
        }

        $this->registerMapSettingsNamespaces();
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_map_pois host binding missing for [{$abstract}]");
        });
    }

    private function registerMapSettingsNamespaces(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

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
                'poi_time_window_days.past' => ['type' => 'integer', 'nullable' => false, 'label' => 'POI Past Window (days)', 'default' => 1, 'order' => 40],
                'poi_time_window_days.future' => ['type' => 'integer', 'nullable' => false, 'label' => 'POI Future Window (days)', 'default' => 30, 'order' => 50],
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
