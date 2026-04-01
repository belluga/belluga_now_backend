<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\DeepLinks\AppLinksIdentifierGatewayAdapter;
use App\Integration\DeepLinks\AppLinksSettingsNamespaceRegistrar;
use App\Integration\DeepLinks\AppLinksSettingsSourceAdapter;
use Belluga\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Illuminate\Support\ServiceProvider;

class DeepLinksIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AppLinksIdentifierGatewayContract::class,
            AppLinksIdentifierGatewayAdapter::class
        );

        $this->app->bind(
            AppLinksSettingsSourceContract::class,
            AppLinksSettingsSourceAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);

        $registrar = $this->app->make(AppLinksSettingsNamespaceRegistrar::class);
        $registrar->register($registry);
    }
}
