<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Email\ResendEmailSettingsNamespaceRegistrar;
use App\Integration\Email\SettingsKernelEmailSettingsSourceAdapter;
use App\Integration\Email\TenantCurrentContextAdapter;
use Belluga\Email\Contracts\EmailSettingsSourceContract;
use Belluga\Email\Contracts\EmailTenantContextContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Illuminate\Support\ServiceProvider;

class EmailIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            EmailSettingsSourceContract::class,
            SettingsKernelEmailSettingsSourceAdapter::class
        );

        $this->app->bind(
            EmailTenantContextContract::class,
            TenantCurrentContextAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        $this->app->make(ResendEmailSettingsNamespaceRegistrar::class)->register($registry);
    }
}
