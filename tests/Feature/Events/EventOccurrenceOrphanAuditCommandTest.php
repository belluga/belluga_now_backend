<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Facades\Artisan;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class EventOccurrenceOrphanAuditCommandTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        EventOccurrence::withTrashed()->forceDelete();
        Event::withTrashed()->forceDelete();
    }

    public function test_orphan_audit_command_reports_live_and_soft_deleted_orphans_for_tenant(): void
    {
        $healthyEvent = Event::query()->create([
            'title' => 'Healthy Event',
            'slug' => 'healthy-event',
            'is_active' => true,
        ])->fresh();

        EventOccurrence::query()->create([
            'event_id' => (string) $healthyEvent?->_id,
            'title' => 'Healthy Occurrence',
            'occurrence_slug' => 'healthy-occurrence',
        ]);

        EventOccurrence::query()->create([
            'event_id' => '68706f6e6f7265646576656e74',
            'title' => 'Live Orphan',
            'occurrence_slug' => 'live-orphan',
        ]);

        $deletedOrphan = EventOccurrence::query()->create([
            'event_id' => '68706f6e6f72656465766570',
            'title' => 'Deleted Orphan',
            'occurrence_slug' => 'deleted-orphan',
        ]);
        $deletedOrphan->delete();

        $exitCode = Artisan::call('events:occurrences:audit-orphans');

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($this->tenant->slug, $payload['tenant_slug']);
        $this->assertSame(3, $payload['totals']['scanned_occurrences']);
        $this->assertSame(2, $payload['totals']['orphan_occurrences']);
        $this->assertSame(1, $payload['totals']['active_bypass']);
        $this->assertSame(1, $payload['totals']['legacy_residue']);

        $rowsBySlug = collect($payload['rows'])->keyBy('occurrence_slug');

        $this->assertSame('active_bypass', $rowsBySlug['live-orphan']['classification']);
        $this->assertSame(
            'missing_parent_event + live_occurrence',
            $rowsBySlug['live-orphan']['classification_basis']
        );
        $this->assertNull($rowsBySlug['live-orphan']['deleted_at']);

        $this->assertSame('legacy_residue', $rowsBySlug['deleted-orphan']['classification']);
        $this->assertSame(
            'missing_parent_event + soft_deleted_occurrence',
            $rowsBySlug['deleted-orphan']['classification_basis']
        );
        $this->assertNotNull($rowsBySlug['deleted-orphan']['deleted_at']);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $initializer */
        $initializer = app(SystemInitializationService::class);

        $initializer->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: [
                'name' => 'Root User',
                'email' => 'root@example.org',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        ));
    }
}
