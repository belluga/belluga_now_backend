<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use App\Integration\Media\TenantSlugMediaScopeResolverAdapter;
use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Tests\TestCase;

class MediaPackageBindingsTest extends TestCase
{
    public function test_media_package_contracts_are_bound_to_host_adapters(): void
    {
        $this->assertInstanceOf(
            TenantSlugMediaScopeResolverAdapter::class,
            $this->app->make(TenantMediaScopeResolverContract::class)
        );
        $this->assertInstanceOf(ModelMediaService::class, $this->app->make(ModelMediaService::class));
    }
}
