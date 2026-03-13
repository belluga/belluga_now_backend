<?php

declare(strict_types=1);

namespace Belluga\PushHandler;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushSettingsMutationContract;
use Belluga\PushHandler\Contracts\PushSettingsStoreContract;
use Belluga\PushHandler\Contracts\PushTelemetryEmitterContract;
use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Services\FcmHttpV1Client;
use Belluga\PushHandler\Services\PushAudienceEligibilityAllowAll;
use Belluga\PushHandler\Services\PushPlanPolicyAllowAll;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class PushHandlerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/push_handler.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/belluga_push_handler.php', 'belluga_push_handler');

        if (! $this->app->bound(PushPlanPolicyContract::class)) {
            $this->app->bind(PushPlanPolicyContract::class, PushPlanPolicyAllowAll::class);
        }

        if (! $this->app->bound(PushAudienceEligibilityContract::class)) {
            $this->app->bind(PushAudienceEligibilityContract::class, PushAudienceEligibilityAllowAll::class);
        }

        if (! $this->app->bound(FcmClientContract::class)) {
            $this->app->bind(FcmClientContract::class, FcmHttpV1Client::class);
        }

        $this->ensureHostBinding(PushAccountContextContract::class);
        $this->ensureHostBinding(PushTenantContextContract::class);
        $this->ensureHostBinding(PushUserGatewayContract::class);
        $this->ensureHostBinding(PushTelemetryEmitterContract::class);
        $this->ensureHostBinding(PushSettingsStoreContract::class);
        $this->ensureHostBinding(PushSettingsMutationContract::class);
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_push_handler host binding missing for [{$abstract}]");
        });
    }
}
