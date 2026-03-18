<?php

declare(strict_types=1);

namespace Tests\Feature\TestSupport;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\InviteStageTestSupportRun;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Illuminate\Support\Str;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class InviteStageTestSupportControllerTest extends TestCaseTenant
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

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        InviteStageTestSupportRun::query()->delete();
        Event::query()->delete();
        InviteShareCode::query()->delete();

        config()->set('app.env', 'stage');
        config()->set('test_support.invites.enabled', true);
        config()->set('test_support.invites.secret_header', 'X-Test-Support-Key');
        config()->set('test_support.invites.secret', 'stage-secret');
        config()->set('test_support.invites.allowed_tenants', [$this->tenant->slug]);
    }

    public function test_harness_is_unavailable_without_stage_environment(): void
    {
        config()->set('app.env', 'local');

        $response = $this->withHeaders([
            'X-Test-Support-Key' => 'stage-secret',
        ])->postJson("{$this->base_api_tenant}test-support/invites/bootstrap", [
            'run_id' => 'run-local',
            'scenario' => 'accept_pending',
        ]);

        $response->assertNotFound();
    }

    public function test_harness_is_unavailable_without_valid_secret(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}test-support/invites/bootstrap", [
            'run_id' => 'run-no-secret',
            'scenario' => 'accept_pending',
        ]);

        $response->assertNotFound();
    }

    public function test_harness_is_unavailable_for_disallowed_tenant(): void
    {
        config()->set('test_support.invites.allowed_tenants', ['another-tenant']);

        $response = $this->withHeaders([
            'X-Test-Support-Key' => 'stage-secret',
        ])->postJson("{$this->base_api_tenant}test-support/invites/bootstrap", [
            'run_id' => 'run-disallowed',
            'scenario' => 'accept_pending',
        ]);

        $response->assertNotFound();
    }

    public function test_bootstrap_state_and_cleanup_are_deterministic_for_accept_pending(): void
    {
        $runId = 'run-'.Str::lower(Str::random(8));

        $bootstrapResponse = $this->withHeaders([
            'X-Test-Support-Key' => 'stage-secret',
        ])->postJson("{$this->base_api_tenant}test-support/invites/bootstrap", [
            'run_id' => $runId,
            'scenario' => 'accept_pending',
        ]);

        $bootstrapResponse->assertOk();
        $bootstrapResponse->assertJsonPath('run_id', $runId);
        $bootstrapResponse->assertJsonPath('tenant.slug', $this->tenant->slug);
        $bootstrapResponse->assertJsonPath('mobile.app_domain_identifier', null);
        $this->assertNotSame('', (string) $bootstrapResponse->json('share_code'));
        $this->assertNotSame('', (string) $bootstrapResponse->json('invitee.email'));

        $stateResponse = $this->withHeaders([
            'X-Test-Support-Key' => 'stage-secret',
        ])->getJson("{$this->base_api_tenant}test-support/invites/state/{$runId}");

        $stateResponse->assertOk();
        $stateResponse->assertJsonPath('run_id', $runId);
        $stateResponse->assertJsonPath('scenario', 'accept_pending');
        $stateResponse->assertJsonCount(1, 'invites');
        $stateResponse->assertJsonPath('invites.0.status', 'pending');

        $cleanupResponse = $this->withHeaders([
            'X-Test-Support-Key' => 'stage-secret',
        ])->postJson("{$this->base_api_tenant}test-support/invites/cleanup", [
            'run_id' => $runId,
        ]);

        $cleanupResponse->assertOk();
        $cleanupResponse->assertJsonPath('run_id', $runId);
        $cleanupResponse->assertJsonPath('deleted', true);

        $this->assertFalse(InviteStageTestSupportRun::query()->where('run_id', $runId)->exists());
        $this->assertSame(0, InviteShareCode::query()->count());
        $this->assertSame(0, Event::query()->count());
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#000000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
        $this->tenant->slug = 'tenant-zeta';
        $this->tenant->subdomain = 'tenant-zeta';
    }
}
