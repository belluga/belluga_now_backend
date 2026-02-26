<?php

declare(strict_types=1);

namespace Belluga\Events;

use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->ensureHostBinding(EventTaxonomyValidationContract::class);
        $this->ensureHostBinding(EventProfileResolverContract::class);
        $this->ensureHostBinding(EventAccountResolverContract::class);
        $this->ensureHostBinding(EventTenantContextContract::class);
        $this->ensureHostBinding(EventProjectionSyncContract::class);
        $this->ensureHostBinding(EventRadiusSettingsContract::class);
        $this->ensureHostBinding(TenantExecutionContextContract::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_events host binding missing for [{$abstract}]");
        });
    }
}
