<?php

declare(strict_types=1);

namespace Belluga\PushHandler;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\EventAudienceResolverContract;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Services\PushPlanPolicyAllowAll;
use Belluga\PushHandler\Services\EventAudienceResolverAllowAll;
use Belluga\PushHandler\Services\FcmClientStub;
use Illuminate\Support\ServiceProvider;

class PushHandlerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/push_handler.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/belluga_push_handler.php', 'belluga_push_handler');

        if (! $this->app->bound(PushPlanPolicyContract::class)) {
            $this->app->bind(PushPlanPolicyContract::class, PushPlanPolicyAllowAll::class);
        }

        if (! $this->app->bound(EventAudienceResolverContract::class)) {
            $this->app->bind(EventAudienceResolverContract::class, EventAudienceResolverAllowAll::class);
        }

        if (! $this->app->bound(FcmClientContract::class)) {
            $this->app->bind(FcmClientContract::class, FcmClientStub::class);
        }
    }
}
