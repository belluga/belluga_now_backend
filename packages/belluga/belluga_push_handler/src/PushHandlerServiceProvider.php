<?php

declare(strict_types=1);

namespace Belluga\PushHandler;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushAudienceEligibilityContract;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Services\PushPlanPolicyAllowAll;
use Belluga\PushHandler\Services\PushAudienceEligibilityAllowAll;
use Belluga\PushHandler\Services\FcmHttpV1Client;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Support\SettingsNamespaceDefinition;
use Illuminate\Support\ServiceProvider;

class PushHandlerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/push_handler.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerPushSettingsNamespaces();
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

    private function registerPushSettingsNamespaces(): void
    {
        if (! $this->app->bound(SettingsRegistryContract::class)) {
            return;
        }

        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        $ability = 'push-settings:update';

        $registry->register(new SettingsNamespaceDefinition(
            namespace: 'push',
            scope: 'tenant',
            label: 'Push',
            groupLabel: 'Notifications',
            ability: $ability,
            fields: [
                'enabled' => ['type' => 'boolean', 'nullable' => false, 'label' => 'Enabled', 'order' => 10],
                'throttles' => ['type' => 'object', 'nullable' => true, 'label' => 'Throttles', 'order' => 20],
                'max_ttl_days' => ['type' => 'integer', 'nullable' => false, 'label' => 'Max TTL Days', 'order' => 30],
            ],
            order: 100,
        ));
    }
}
