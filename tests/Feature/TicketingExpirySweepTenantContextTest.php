<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Ticketing\ExpireIssuedTicketUnitsJob;
use App\Models\Landlord\Tenant;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Illuminate\Support\Carbon;
use Tests\TestCaseAuthenticated;

class TicketingExpirySweepTenantContextTest extends TestCaseAuthenticated
{
    public function test_ticket_expiry_sweep_processes_occurrences_across_tenant_contexts(): void
    {
        $primaryTenant = Tenant::query()
            ->where('slug', $this->landlord->tenant_primary->slug)
            ->firstOrFail();
        $secondaryTenant = $this->ensureSecondaryTenant();

        $primaryTenant->makeCurrent();
        $primaryOccurrence = EventOccurrence::query()->create([
            'event_id' => 'ticketing-primary-expiry-event',
            'occurrence_index' => 0,
            'occurrence_slug' => 'ticketing-primary-expiry-occurrence',
            'starts_at' => Carbon::now()->subHours(6),
            'ends_at' => Carbon::now()->subHours(4),
            'is_event_published' => true,
        ]);
        $primaryTenant->forgetCurrent();

        $secondaryTenant->makeCurrent();
        $secondaryOccurrence = EventOccurrence::query()->create([
            'event_id' => 'ticketing-secondary-expiry-event',
            'occurrence_index' => 0,
            'occurrence_slug' => 'ticketing-secondary-expiry-occurrence',
            'starts_at' => Carbon::now()->subHours(8),
            'ends_at' => Carbon::now()->subHours(6),
            'is_event_published' => true,
        ]);
        $secondaryTenant->forgetCurrent();

        $expiredOccurrenceIds = [];

        $lifecycle = \Mockery::mock(TicketUnitLifecycleService::class);
        $lifecycle->shouldReceive('expireIssuedByOccurrence')
            ->andReturnUsing(
                static function (string $occurrenceId) use (&$expiredOccurrenceIds): int {
                    $expiredOccurrenceIds[] = $occurrenceId;

                    return 0;
                }
            );

        $settingsStore = \Mockery::mock(SettingsStoreContract::class);
        $settingsStore->shouldReceive('getNamespaceValue')
            ->with('tenant', 'ticketing_lifecycle')
            ->andReturn([]);

        $this->app->instance(TicketUnitLifecycleService::class, $lifecycle);
        $this->app->instance(SettingsStoreContract::class, $settingsStore);

        app(TenantExecutionContextContract::class)->runForEachTenant(static function (): void {
            app()->call([new ExpireIssuedTicketUnitsJob(batchSize: 5000), 'handle']);
        });

        $this->assertContains((string) $primaryOccurrence->getAttribute('_id'), $expiredOccurrenceIds);
        $this->assertContains((string) $secondaryOccurrence->getAttribute('_id'), $expiredOccurrenceIds);
    }

    private function ensureSecondaryTenant(): Tenant
    {
        $existing = Tenant::withTrashed()
            ->where('slug', 'ticketing-expiry-secondary')
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return Tenant::create([
            'name' => 'Ticketing Expiry Secondary',
            'subdomain' => 'ticketing-expiry-secondary',
            'app_domains' => ['com.ticketing.expiry.secondary'],
        ]);
    }
}
