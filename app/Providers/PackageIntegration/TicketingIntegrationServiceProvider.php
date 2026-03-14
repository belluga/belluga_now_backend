<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Integration\Ticketing\CheckoutOrchestratorAdapter;
use App\Integration\Ticketing\EventTemplateReadAdapter;
use App\Integration\Ticketing\OccurrencePublicationAdapter;
use App\Integration\Ticketing\OccurrenceReadAdapter;
use App\Integration\Ticketing\TenantTicketingPolicyAdapter;
use App\Integration\Ticketing\TicketingSettingsNamespaceRegistrar;
use App\Integration\Ticketing\TicketingSettingsStoreAdapter;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
use Belluga\Ticketing\Contracts\TicketingSettingsStoreContract;
use Illuminate\Support\ServiceProvider;

class TicketingIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OccurrenceReadContract::class,
            OccurrenceReadAdapter::class
        );

        $this->app->bind(
            OccurrencePublicationContract::class,
            OccurrencePublicationAdapter::class
        );

        $this->app->bind(
            EventTemplateReadContract::class,
            EventTemplateReadAdapter::class
        );

        $this->app->bind(
            CheckoutOrchestratorContract::class,
            CheckoutOrchestratorAdapter::class
        );

        $this->app->bind(
            TicketingPolicyContract::class,
            TenantTicketingPolicyAdapter::class
        );

        $this->app->bind(
            TicketingSettingsStoreContract::class,
            TicketingSettingsStoreAdapter::class
        );
    }

    public function boot(): void
    {
        /** @var SettingsRegistryContract $registry */
        $registry = $this->app->make(SettingsRegistryContract::class);
        (new TicketingSettingsNamespaceRegistrar)->register($registry);
    }
}
