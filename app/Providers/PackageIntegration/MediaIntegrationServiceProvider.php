<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Media\TenantSlugMediaScopeResolverAdapter;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Illuminate\Support\ServiceProvider;

class MediaIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            TenantMediaScopeResolverContract::class,
            TenantSlugMediaScopeResolverAdapter::class
        );
    }
}
