<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Events\AccountSlugResolverAdapter;
use App\Integration\Events\EventTypeResolverAdapter;
use Belluga\MapPois\Integration\Events\MapPoiEventProjectionSyncAdapter;
use App\Integration\Events\EventTaxonomyValidationAdapter;
use Belluga\MapPois\Integration\Events\MapPoiEventAsyncJobSignaturesAdapter;
use App\Integration\Events\TenantCapabilitySettingsAdapter;
use App\Integration\Events\TenantContextAdapter;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Events\TenantRadiusSettingsAdapter;
use Belluga\Events\Application\Operations\QueueEventAsyncMetricsProvider;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventAsyncQueueMetricsProviderContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\EventTypeResolverContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Parties\InMemoryEventPartyMapperRegistry;
use Tests\TestCase;

class EventsPackageBindingsTest extends TestCase
{
    public function testEventsPackageContractsAreBoundToAppAdapters(): void
    {
        $this->assertInstanceOf(
            EventTaxonomyValidationAdapter::class,
            $this->app->make(EventTaxonomyValidationContract::class)
        );
        $this->assertInstanceOf(
            EventTypeResolverAdapter::class,
            $this->app->make(EventTypeResolverContract::class)
        );
        $this->assertInstanceOf(
            AccountProfileResolverAdapter::class,
            $this->app->make(EventProfileResolverContract::class)
        );
        $this->assertInstanceOf(
            AccountSlugResolverAdapter::class,
            $this->app->make(EventAccountResolverContract::class)
        );
        $this->assertInstanceOf(
            TenantCapabilitySettingsAdapter::class,
            $this->app->make(EventCapabilitySettingsContract::class)
        );
        $this->assertInstanceOf(
            InMemoryEventPartyMapperRegistry::class,
            $this->app->make(EventPartyMapperRegistryContract::class)
        );
        $this->assertInstanceOf(
            TenantContextAdapter::class,
            $this->app->make(EventTenantContextContract::class)
        );
        $this->assertInstanceOf(
            MapPoiEventProjectionSyncAdapter::class,
            $this->app->make(EventProjectionSyncContract::class)
        );
        $this->assertInstanceOf(
            TenantRadiusSettingsAdapter::class,
            $this->app->make(EventRadiusSettingsContract::class)
        );
        $this->assertInstanceOf(
            TenantExecutionContextAdapter::class,
            $this->app->make(TenantExecutionContextContract::class)
        );
        $this->assertInstanceOf(
            QueueEventAsyncMetricsProvider::class,
            $this->app->make(EventAsyncQueueMetricsProviderContract::class)
        );
        $this->assertInstanceOf(
            MapPoiEventAsyncJobSignaturesAdapter::class,
            $this->app->make(EventAsyncJobSignaturesContract::class)
        );
    }
}
