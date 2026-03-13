<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Application\Push\PushAudienceEligibilityService;
use App\Integration\Push\PushAccountContextAdapter;
use App\Integration\Push\PushSettingsMutationAdapter;
use App\Integration\Push\PushSettingsNamespaceRegistrar;
use App\Integration\Push\PushSettingsStoreAdapter;
use App\Integration\Push\PushTelemetryEmitterAdapter;
use App\Integration\Push\PushTenantContextAdapter;
use App\Integration\Push\PushUserGatewayAdapter;
use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\PushSettingsMutationContract;
use Belluga\PushHandler\Contracts\PushSettingsStoreContract;
use Belluga\PushHandler\Contracts\PushTelemetryEmitterContract;
use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Illuminate\Support\ServiceProvider;

class PushIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
            PushSettingsStoreContract::class,
            PushSettingsStoreAdapter::class
        );

        $this->app->bind(
            PushSettingsMutationContract::class,
            PushSettingsMutationAdapter::class
        );
    }

    public function boot(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        (new PushSettingsNamespaceRegistrar)->register($registry);
    }
}
