<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordUserAccessServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private LandlordUser $user;

    private LandlordUserAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->user = LandlordUser::query()->firstOrFail();
        $this->service = $this->app->make(LandlordUserAccessService::class);
    }

    public function testTenantAccessIds(): void
    {
        $ids = $this->service->tenantAccessIds($this->user);

        $this->assertNotEmpty($ids);
        $this->assertContains((string) Tenant::query()->firstOrFail()->_id, $ids);
    }

    public function testPermissionsResolveFromTenantRoles(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $permissions = $this->service->permissions($this->user, $tenant);

        $this->assertContains('*', $permissions);
    }

    public function testSyncCredentialCreatesPasswordEntry(): void
    {
        $credential = $this->service->syncCredential($this->user, 'password', 'sync@example.org', 'secret-hash');

        $this->assertSame('password', $credential['provider']);
        $this->assertSame('sync@example.org', $credential['subject']);
    }

    public function testEnsureEmailAppendsNewContact(): void
    {
        $this->service->ensureEmail($this->user, 'added@example.org');

        $this->user->refresh();
        $this->assertContains('added@example.org', $this->user->emails ?? []);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Gamma', 'subdomain' => 'tenant-gamma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-gamma.test']
        );

        $service->initialize($payload);

        LandlordRole::query()->first()?->users()->save(LandlordUser::query()->first());
    }
}
