<?php

declare(strict_types=1);

namespace Tests\Unit\Ticketing;

use App\Integration\Ticketing\CheckoutOrchestratorAdapter;
use App\Integration\Ticketing\EventTemplateReadAdapter;
use App\Integration\Ticketing\OccurrencePublicationAdapter;
use App\Integration\Ticketing\OccurrenceReadAdapter;
use App\Integration\Ticketing\TenantTicketingPolicyAdapter;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Ticketing\Application\Guards\OccurrenceWriteGuardService;
use Belluga\Ticketing\Application\Admission\TicketAdmissionService;
use Belluga\Ticketing\Application\Checkout\TicketCheckoutService;
use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;
use Belluga\Ticketing\Contracts\EventTemplateReadContract;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;
use Belluga\Ticketing\Contracts\OccurrenceReadContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;
use Tests\TestCase;

class TicketingPackageBindingsTest extends TestCase
{
    public function testTicketingContractsAreBoundToHostAdapters(): void
    {
        $this->assertInstanceOf(OccurrenceReadAdapter::class, $this->app->make(OccurrenceReadContract::class));
        $this->assertInstanceOf(OccurrencePublicationAdapter::class, $this->app->make(OccurrencePublicationContract::class));
        $this->assertInstanceOf(EventTemplateReadAdapter::class, $this->app->make(EventTemplateReadContract::class));
        $this->assertInstanceOf(CheckoutOrchestratorAdapter::class, $this->app->make(CheckoutOrchestratorContract::class));
        $this->assertInstanceOf(TenantTicketingPolicyAdapter::class, $this->app->make(TicketingPolicyContract::class));
        $this->assertInstanceOf(OccurrenceWriteGuardService::class, $this->app->make(OccurrenceWriteGuardService::class));
        $this->assertInstanceOf(TicketAdmissionService::class, $this->app->make(TicketAdmissionService::class));
        $this->assertInstanceOf(TicketCheckoutService::class, $this->app->make(TicketCheckoutService::class));
        $this->assertInstanceOf(TicketUnitLifecycleService::class, $this->app->make(TicketUnitLifecycleService::class));
    }

    public function testTicketingNamespacesAreRegisteredInSettingsRegistry(): void
    {
        $registry = $this->app->make(SettingsRegistryContract::class);

        $this->assertNotNull($registry->find('ticketing_core', 'tenant'));
        $this->assertNotNull($registry->find('ticketing_hold_queue', 'tenant'));
        $this->assertNotNull($registry->find('ticketing_seating', 'tenant'));
        $this->assertNotNull($registry->find('ticketing_validation', 'tenant'));
        $this->assertNotNull($registry->find('ticketing_security', 'tenant'));
        $this->assertNotNull($registry->find('ticketing_lifecycle', 'tenant'));
        $this->assertNotNull($registry->find('checkout_core', 'tenant'));
        $this->assertNotNull($registry->find('checkout_ticketing', 'tenant'));
        $this->assertNotNull($registry->find('participation_presence', 'tenant'));
        $this->assertNotNull($registry->find('participation_proofs', 'tenant'));
    }
}
