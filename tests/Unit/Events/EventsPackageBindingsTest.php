<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Events\EventMapPoiProjectionSyncAdapter;
use App\Integration\Events\EventTaxonomyValidationAdapter;
use App\Integration\Events\TenantExecutionContextAdapter;
use App\Integration\Events\TenantRadiusSettingsAdapter;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
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
            AccountProfileResolverAdapter::class,
            $this->app->make(EventProfileResolverContract::class)
        );
        $this->assertInstanceOf(
            EventMapPoiProjectionSyncAdapter::class,
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
    }
}
