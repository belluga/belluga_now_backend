<?php

declare(strict_types=1);

namespace Belluga\Events;

use Belluga\Events\Application\Operations\EventDlqAlertService;
use Belluga\Events\Application\Operations\PackageEventAsyncJobSignatures;
use Belluga\Events\Application\Operations\QueueEventAsyncMetricsProvider;
use Belluga\Events\Capabilities\InMemoryEventCapabilityRegistry;
use Belluga\Events\Capabilities\MultipleOccurrencesCapabilityHandler;
use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventAsyncQueueMetricsProviderContract;
use Belluga\Events\Contracts\EventAccountResolverContract;
use Belluga\Events\Contracts\EventCapabilityRegistryContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Contracts\EventPartyMapperRegistryContract;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventProjectionSyncContract;
use Belluga\Events\Contracts\EventRadiusSettingsContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Contracts\EventTaxonomyValidationContract;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventCapabilityRegistryContract::class, static function () {
            $registry = new InMemoryEventCapabilityRegistry();
            $registry->register(new MultipleOccurrencesCapabilityHandler());

            return $registry;
        });

        $this->app->bind(EventAsyncQueueMetricsProviderContract::class, QueueEventAsyncMetricsProvider::class);
        $this->app->singletonIf(EventAsyncJobSignaturesContract::class, PackageEventAsyncJobSignatures::class);

        $this->ensureHostBinding(EventTaxonomyValidationContract::class);
        $this->ensureHostBinding(EventProfileResolverContract::class);
        $this->ensureHostBinding(EventAccountResolverContract::class);
        $this->ensureHostBinding(EventCapabilitySettingsContract::class);
        $this->ensureHostBinding(EventPartyMapperRegistryContract::class);
        $this->ensureHostBinding(EventTenantContextContract::class);
        $this->ensureHostBinding(EventProjectionSyncContract::class);
        $this->ensureHostBinding(EventRadiusSettingsContract::class);
        $this->ensureHostBinding(TenantExecutionContextContract::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Queue::failing(function (JobFailed $event): void {
            $this->app->make(EventDlqAlertService::class)->handle($event);
        });
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
