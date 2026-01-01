<?php

declare(strict_types=1);

namespace Belluga\PushHandler;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Services\PushPlanPolicyAllowAll;
use Belluga\PushHandler\Services\PushAudienceEligibilityAllowAll;
use Belluga\PushHandler\Services\FcmHttpV1Client;
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

        if (! $this->app->bound(PushAudienceEligibilityContract::class)) {
            $this->app->bind(PushAudienceEligibilityContract::class, PushAudienceEligibilityAllowAll::class);
        }

        if (! $this->app->bound(FcmClientContract::class)) {
            $this->app->bind(FcmClientContract::class, FcmHttpV1Client::class);
        }
    }
}
