<?php

declare(strict_types=1);

namespace Belluga\Media;

use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModelMediaService::class);
        $this->ensureHostBinding(TenantMediaScopeResolverContract::class);
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_media host binding missing for [{$abstract}]");
        });
    }
}
