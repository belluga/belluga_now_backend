<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Events\AccountSlugResolverAdapter;
use App\Integration\Events\EventParties\ArtistEventPartyMapper;
use App\Integration\Events\EventParties\VenueEventPartyMapper;
use App\Integration\Events\EventTaxonomyValidationAdapter;
use App\Integration\Events\EventTypeResolverAdapter;
use App\Integration\Events\TenantCapabilitySettingsAdapter;
use App\Integration\Events\TenantContextAdapter;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Events\TenantRadiusSettingsAdapter;
use App\Integration\MapPois\MapPoiRegistryAdapter;
use App\Integration\MapPois\MapPoiSettingsAdapter;
use App\Integration\MapPois\MapPoiSourceReaderAdapter;
use App\Integration\MapPois\MapPoiTenantContextAdapter;
use App\Integration\Settings\TenantScopeContextAdapter;
use App\Integration\Push\PushAccountContextAdapter;
use App\Integration\Push\PushTelemetryEmitterAdapter;
use App\Integration\Push\PushTenantContextAdapter;
use App\Integration\Push\PushUserGatewayAdapter;
use App\Integration\Ticketing\CheckoutOrchestratorAdapter;
use App\Integration\Ticketing\EventTemplateReadAdapter;
use App\Integration\Ticketing\OccurrencePublicationAdapter;
use App\Integration\Ticketing\OccurrenceReadAdapter;
use App\Integration\Ticketing\TenantTicketingPolicyAdapter;
use App\Application\Push\PushAudienceEligibilityService;
use App\Application\Telemetry\Contracts\TelemetryEmitterContract;
use App\Application\Telemetry\TelemetryEmitter;
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
use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\MapPois\Contracts\MapPoiRegistryContract;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Contracts\MapPoiTenantContextContract;
use Belluga\MapPois\Integration\Events\MapPoiEventAsyncJobSignaturesAdapter;
use Belluga\MapPois\Integration\Events\MapPoiEventProjectionSyncAdapter;
use Belluga\Events\Parties\InMemoryEventPartyMapperRegistry;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushTelemetryEmitterContract;
use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\TenantScopeContextContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
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
            PushAccountContextContract::class,
            PushAccountContextAdapter::class
        );

        $this->app->bind(
            PushTenantContextContract::class,
            PushTenantContextAdapter::class
        );

        $this->app->bind(
            PushUserGatewayContract::class,
            PushUserGatewayAdapter::class
        );

        $this->app->bind(
            PushTelemetryEmitterContract::class,
            PushTelemetryEmitterAdapter::class
        );

        $this->app->bind(
            TelemetryEmitterContract::class,
            TelemetryEmitter::class
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
            MapPoiEventProjectionSyncAdapter::class
        );

        $this->app->bind(
            EventRadiusSettingsContract::class,
            TenantRadiusSettingsAdapter::class
        );

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

        $this->app->bind(
            TenantExecutionContextContract::class,
            TenantExecutionContextAdapter::class
        );

        $this->app->bind(
            TenantScopeContextContract::class,
            TenantScopeContextAdapter::class
        );

        $this->app->bind(
            OccurrenceReadContract::class,
            OccurrenceReadAdapter::class
        );

        $this->app->bind(
            OccurrencePublicationContract::class,
            OccurrencePublicationAdapter::class
        );

        $this->app->bind(
            EventTemplateReadContract::class,
            EventTemplateReadAdapter::class
        );

        $this->app->bind(
            CheckoutOrchestratorContract::class,
            CheckoutOrchestratorAdapter::class
        );

        $this->app->bind(
            TicketingPolicyContract::class,
            TenantTicketingPolicyAdapter::class
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
    }

    private function registerCoreSettingsNamespaces(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

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
