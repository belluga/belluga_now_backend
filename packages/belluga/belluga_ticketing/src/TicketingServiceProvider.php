<?php

declare(strict_types=1);

namespace Belluga\Ticketing;

use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Checkout\CheckoutPayloadAssembler;
use Belluga\Ticketing\Application\Checkout\TicketCheckoutService;
use Belluga\Ticketing\Application\Guards\OccurrenceWriteGuardService;
use Belluga\Ticketing\Application\Holds\TicketHoldService;
use Belluga\Ticketing\Application\Inventory\InventoryMutationService;
use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Belluga\Ticketing\Application\Promotions\TicketPromotionQuotaService;
use Belluga\Ticketing\Application\Promotions\TicketPromotionResolverService;
use Belluga\Ticketing\Application\Queue\TicketQueueService;
use Belluga\Ticketing\Application\Realtime\TicketRealtimeStreamService;
use Belluga\Ticketing\Application\Security\TicketingCommandRateLimiter;
use Belluga\Ticketing\Application\Settings\TicketingRuntimeSettingsService;
use Belluga\Ticketing\Application\Transactions\TenantTransactionRunner;
use Belluga\Ticketing\Application\TransferReissue\TicketTransferReissueService;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
use Belluga\Ticketing\Contracts\TicketingSettingsStoreContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class TicketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OccurrenceWriteGuardService::class);
        $this->app->singleton(TicketingRuntimeSettingsService::class);
        $this->app->singleton(TenantTransactionRunner::class);
        $this->app->singleton(InventoryMutationService::class);
        $this->app->singleton(TicketQueueService::class);
        $this->app->singleton(TicketRealtimeStreamService::class);
        $this->app->singleton(TicketingCommandRateLimiter::class);
        $this->app->singleton(TicketOutboxEmitter::class);
        $this->app->singleton(TicketPromotionResolverService::class);
        $this->app->singleton(TicketPromotionQuotaService::class);
        $this->app->singleton(TicketHoldService::class);
        $this->app->singleton(CheckoutPayloadAssembler::class);
        $this->app->singleton(TicketAdmissionService::class);
        $this->app->singleton(TicketCheckoutService::class);
        $this->app->singleton(TicketUnitLifecycleService::class);
        $this->app->singleton(TicketTransferReissueService::class);

        $this->ensureHostBinding(OccurrenceReadContract::class);
        $this->ensureHostBinding(OccurrencePublicationContract::class);
        $this->ensureHostBinding(EventTemplateReadContract::class);
        $this->ensureHostBinding(CheckoutOrchestratorContract::class);
        $this->ensureHostBinding(TicketingPolicyContract::class);
        $this->ensureHostBinding(TicketingSettingsStoreContract::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_ticketing host binding missing for [{$abstract}]");
        });
    }
}
