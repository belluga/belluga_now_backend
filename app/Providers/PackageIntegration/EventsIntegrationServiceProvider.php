<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Events\AccountSlugResolverAdapter;
use App\Integration\Events\EventParties\ArtistEventPartyMapper;
use App\Integration\Events\EventParties\VenueEventPartyMapper;
use App\Integration\Events\EventTaxonomyValidationAdapter;
use App\Integration\Events\EventTypeResolverAdapter;
use App\Integration\Events\MapPoiEventAsyncJobSignaturesAdapter;
use App\Integration\Events\TenantCapabilitySettingsAdapter;
use App\Integration\Events\TenantContextAdapter;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Events\TenantRadiusSettingsAdapter;
use App\Integration\Ticketing\EventTemplateReadAdapter;
use App\Listeners\Events\SyncMapPoiOnEventCreated;
use App\Listeners\Events\SyncMapPoiOnEventDeleted;
use App\Listeners\Events\SyncMapPoiOnEventUpdated;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTemplateSnapshotReadContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Parties\InMemoryEventPartyMapperRegistry;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventsIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            EventTaxonomyValidationContract::class,
            EventTaxonomyValidationAdapter::class
        );

        $this->app->bind(
            EventTypeResolverContract::class,
            EventTypeResolverAdapter::class
        );

        $this->app->bind(
            EventProfileResolverContract::class,
            AccountProfileResolverAdapter::class
        );

        $this->app->bind(
            EventAccountResolverContract::class,
            AccountSlugResolverAdapter::class
        );

        $this->app->bind(
            EventCapabilitySettingsContract::class,
            TenantCapabilitySettingsAdapter::class
        );

        $this->app->bind(
            EventAsyncJobSignaturesContract::class,
            MapPoiEventAsyncJobSignaturesAdapter::class
        );

        $this->app->singleton(
            EventPartyMapperRegistryContract::class,
            static function () {
                $registry = new InMemoryEventPartyMapperRegistry;
                $registry->register(new VenueEventPartyMapper);
                $registry->register(new ArtistEventPartyMapper);

                return $registry;
            }
        );

        $this->app->bind(
            EventTenantContextContract::class,
            TenantContextAdapter::class
        );

        $this->app->bind(
            EventRadiusSettingsContract::class,
            TenantRadiusSettingsAdapter::class
        );

        $this->app->bind(
            EventTemplateSnapshotReadContract::class,
            EventTemplateReadAdapter::class
        );

        $this->app->bind(
            TenantExecutionContextContract::class,
            TenantExecutionContextAdapter::class
        );
    }

    public function boot(): void
    {
        Event::listen(EventCreated::class, SyncMapPoiOnEventCreated::class);
        Event::listen(EventUpdated::class, SyncMapPoiOnEventUpdated::class);
        Event::listen(EventDeleted::class, SyncMapPoiOnEventDeleted::class);

        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'events',
            scope: 'tenant',
            label: 'Events',
            groupLabel: 'Core',
            ability: 'events:read',
            fields: [
                'mode' => [
                    'type' => 'string',
                    'nullable' => false,
                    'label' => 'Mode',
                    'label_i18n_key' => 'settings.events.mode.label',
                    'options' => [
                        ['value' => 'basic', 'label' => 'Basic', 'label_i18n_key' => 'settings.events.mode.option.basic'],
                        ['value' => 'advanced', 'label' => 'Advanced', 'label_i18n_key' => 'settings.events.mode.option.advanced'],
                    ],
                    'default' => 'basic',
                    'order' => 5,
                ],
                'default_duration_hours' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'Default Event Duration (hours)',
                    'label_i18n_key' => 'settings.events.default_duration_hours.label',
                    'order' => 10,
                ],
                'stock_enabled' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Stock Enabled',
                    'label_i18n_key' => 'settings.events.stock_enabled.label',
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    ['field_id' => 'events.mode', 'operator' => 'equals', 'value' => 'advanced'],
                                ],
                            ],
                        ],
                    ],
                    'order' => 20,
                ],
                'capabilities.multiple_occurrences.allow_multiple' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Allow Multiple Occurrences',
                    'label_i18n_key' => 'settings.events.capabilities.multiple_occurrences.allow_multiple.label',
                    'default' => false,
                    'group' => 'capabilities.multiple_occurrences',
                    'group_label' => 'Multiple Occurrences',
                    'group_label_i18n_key' => 'settings.events.group.capabilities.multiple_occurrences.label',
                    'order' => 30,
                ],
                'capabilities.multiple_occurrences.max_occurrences' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'label' => 'Maximum Occurrences',
                    'label_i18n_key' => 'settings.events.capabilities.multiple_occurrences.max_occurrences.label',
                    'default' => null,
                    'group' => 'capabilities.multiple_occurrences',
                    'group_label' => 'Multiple Occurrences',
                    'group_label_i18n_key' => 'settings.events.group.capabilities.multiple_occurrences.label',
                    'visible_if' => [
                        'groups' => [
                            [
                                'rules' => [
                                    [
                                        'field_id' => 'events.capabilities.multiple_occurrences.allow_multiple',
                                        'operator' => 'equals',
                                        'value' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'order' => 40,
                ],
                'capabilities.map_poi.available' => [
                    'type' => 'boolean',
                    'nullable' => false,
                    'label' => 'Map POI Capability Available',
                    'label_i18n_key' => 'settings.events.capabilities.map_poi.available.label',
                    'default' => true,
                    'group' => 'capabilities.map_poi',
                    'group_label' => 'Map POI',
                    'group_label_i18n_key' => 'settings.events.group.capabilities.map_poi.label',
                    'order' => 50,
                ],
            ],
            order: 20,
            labelI18nKey: 'settings.events.namespace.label',
            description: 'Event defaults and publication behavior.',
            descriptionI18nKey: 'settings.events.namespace.description',
            icon: 'event',
        ));
    }
}
