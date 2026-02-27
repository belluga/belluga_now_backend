<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Events\AccountSlugResolverAdapter;
use App\Integration\Events\EventMapPoiProjectionSyncAdapter;
use App\Integration\Events\EventParties\ArtistEventPartyMapper;
use App\Integration\Events\EventParties\VenueEventPartyMapper;
use App\Integration\Events\EventTaxonomyValidationAdapter;
use App\Integration\Events\TenantCapabilitySettingsAdapter;
use App\Integration\Events\TenantContextAdapter;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Events\TenantRadiusSettingsAdapter;
use App\Integration\Settings\TenantScopeContextAdapter;
use App\Listeners\EventsPackage\SyncMapPoiOnEventCreated;
use App\Listeners\EventsPackage\SyncMapPoiOnEventDeleted;
use App\Listeners\EventsPackage\SyncMapPoiOnEventUpdated;
use App\Application\Push\PushAudienceEligibilityService;
use App\Application\Media\ExternalImageDnsResolverContract;
use App\Application\Media\SystemExternalImageDnsResolver;
use App\Http\Api\v1\Controllers\ProfileControllerLandlord;
use App\Http\Api\v1\Controllers\ProfileControllerTenant;
use App\Http\Api\v1\Requests\ResetPasswordRequestContract;
use App\Http\Api\v1\Requests\ResetPasswordRequestLandlord;
use App\Http\Api\v1\Requests\ResetPasswordRequestTenant;
use App\Http\Api\v1\Requests\UpdateProfileRequestContract;
use App\Http\Api\v1\Requests\UpdateProfileRequestLandlord;
use App\Http\Api\v1\Requests\UpdateProfileRequestTenant;
use App\Models\Landlord\PersonalAccessToken;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Domain\Events\EventCreated;
use Belluga\Events\Domain\Events\EventDeleted;
use Belluga\Events\Domain\Events\EventUpdated;
use Belluga\Events\Parties\InMemoryEventPartyMapperRegistry;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Application\Tenants\TenantDomainResolverService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantDomainResolverService::class, function ($app) {
            return new TenantDomainResolverService();
        });

        $this->app->bind(
            ResetPasswordRequestContract::class,
            ResetPasswordRequestLandlord::class
        );

        $this->app->bind(
            UpdateProfileRequestContract::class,
            UpdateProfileRequestLandlord::class
        );

        $this->app->bind(
            PushAudienceEligibilityContract::class,
            PushAudienceEligibilityService::class
        );

        $this->app->bind(
            ExternalImageDnsResolverContract::class,
            SystemExternalImageDnsResolver::class
        );

        $this->app->bind(
            EventTaxonomyValidationContract::class,
            EventTaxonomyValidationAdapter::class
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

        $this->app->singleton(
            EventPartyMapperRegistryContract::class,
            static function () {
                $registry = new InMemoryEventPartyMapperRegistry();
                $registry->register(new VenueEventPartyMapper());
                $registry->register(new ArtistEventPartyMapper());

                return $registry;
            }
        );

        $this->app->bind(
            EventTenantContextContract::class,
            TenantContextAdapter::class
        );

        $this->app->bind(
            EventProjectionSyncContract::class,
            EventMapPoiProjectionSyncAdapter::class
        );

        $this->app->bind(
            EventRadiusSettingsContract::class,
            TenantRadiusSettingsAdapter::class
        );

        $this->app->bind(
            TenantExecutionContextContract::class,
            TenantExecutionContextAdapter::class
        );

        $this->app->bind(
            TenantScopeContextContract::class,
            TenantScopeContextAdapter::class
        );

        $this->app->when(ProfileControllerLandlord::class)
            ->needs(UpdateProfileRequestContract::class)
            ->give(function ($app) {
                return $app->make(UpdateProfileRequestLandlord::class);
            });

        $this->app->when(ProfileControllerLandlord::class)
            ->needs(ResetPasswordRequestContract::class)
            ->give(function ($app) {
                return $app->make(ResetPasswordRequestLandlord::class);
            });

        $this->app->when(ProfileControllerTenant::class)
            ->needs(UpdateProfileRequestContract::class)
            ->give(function ($app) {
                return $app->make(UpdateProfileRequestTenant::class);
            });

        $this->app->when(ProfileControllerTenant::class)
            ->needs(ResetPasswordRequestContract::class)
            ->give(function ($app) {
                return $app->make(ResetPasswordRequestTenant::class);
            });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->registerCoreSettingsNamespaces();

        Event::listen(EventCreated::class, SyncMapPoiOnEventCreated::class);
        Event::listen(EventUpdated::class, SyncMapPoiOnEventUpdated::class);
        Event::listen(EventDeleted::class, SyncMapPoiOnEventDeleted::class);
    }

    private function registerCoreSettingsNamespaces(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(SettingsRegistryContract::class);

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'map_ui',
            scope: 'tenant',
            label: 'Map UI',
            groupLabel: 'Core',
            ability: 'account-users:view',
            fields: [
                'radius.min_km' => [
                    'type' => 'number',
                    'nullable' => false,
                    'label' => 'Radius Min (KM)',
                    'label_i18n_key' => 'settings.map_ui.radius.min_km.label',
                    'group' => 'radius',
                    'group_label' => 'Radius',
                    'group_label_i18n_key' => 'settings.map_ui.group.radius.label',
                    'order' => 10,
                ],
                'radius.default_km' => [
                    'type' => 'number',
                    'nullable' => false,
                    'label' => 'Radius Default (KM)',
                    'label_i18n_key' => 'settings.map_ui.radius.default_km.label',
                    'group' => 'radius',
                    'group_label' => 'Radius',
                    'group_label_i18n_key' => 'settings.map_ui.group.radius.label',
                    'order' => 20,
                ],
                'radius.max_km' => [
                    'type' => 'number',
                    'nullable' => false,
                    'label' => 'Radius Max (KM)',
                    'label_i18n_key' => 'settings.map_ui.radius.max_km.label',
                    'group' => 'radius',
                    'group_label' => 'Radius',
                    'group_label_i18n_key' => 'settings.map_ui.group.radius.label',
                    'order' => 30,
                ],
                'poi_time_window_days.past' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'POI Past Window (days)',
                    'label_i18n_key' => 'settings.map_ui.poi_time_window_days.past.label',
                    'group' => 'poi_time_window_days',
                    'group_label' => 'POI Time Window',
                    'group_label_i18n_key' => 'settings.map_ui.group.poi_time_window_days.label',
                    'order' => 40,
                ],
                'poi_time_window_days.future' => [
                    'type' => 'integer',
                    'nullable' => false,
                    'label' => 'POI Future Window (days)',
                    'label_i18n_key' => 'settings.map_ui.poi_time_window_days.future.label',
                    'group' => 'poi_time_window_days',
                    'group_label' => 'POI Time Window',
                    'group_label_i18n_key' => 'settings.map_ui.group.poi_time_window_days.label',
                    'order' => 50,
                ],
            ],
            order: 10,
            labelI18nKey: 'settings.map_ui.namespace.label',
            description: 'Map and POI defaults.',
            descriptionI18nKey: 'settings.map_ui.namespace.description',
            icon: 'map',
        ));

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
            ],
            order: 20,
            labelI18nKey: 'settings.events.namespace.label',
            description: 'Event defaults and publication behavior.',
            descriptionI18nKey: 'settings.events.namespace.description',
            icon: 'event',
        ));
    }
}
