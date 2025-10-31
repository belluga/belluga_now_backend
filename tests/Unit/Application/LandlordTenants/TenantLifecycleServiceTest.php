<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordTenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\LandlordTenants\TenantLifecycleService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantLifecycleServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private LandlordUser $operator;

    private TenantLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->operator = LandlordUser::query()->firstOrFail();
        $this->service = $this->app->make(TenantLifecycleService::class);
    }

    public function testPaginateReturnsAccessibleTenants(): void
    {
        $paginator = $this->service->paginate($this->operator, false, 15);

        $this->assertGreaterThanOrEqual(1, $paginator->total());

        $ids = collect($paginator->items())->pluck('id')->all();
        $firstTenantId = (string) Tenant::query()->firstOrFail()->_id;

        $this->assertContains($firstTenantId, $ids);
    }

    public function testCreatePersistsTenantAndAssignsRoleToOperator(): void
    {
        $payload = $this->makeTenantPayload('Delta Stores');

        $result = $this->service->create($payload, $this->operator);
        $tenant = $result['tenant'];
        $role = $result['role'];

        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertInstanceOf(TenantRoleTemplate::class, $role);
        $this->assertSame('Delta Stores', $tenant->name);
        $this->assertSame('*', $role->permissions[0] ?? null);

        $this->operator->refresh();
        $this->assertContains((string) $tenant->_id, $this->operator->getAccessToIds());
    }

    public function testUpdateMutatesTenantAttributes(): void
    {
        $payload = $this->makeTenantPayload('Gamma Retail');
        $tenant = $this->service->create($payload, $this->operator)['tenant'];

        $updated = $this->service->update($tenant, [
            'description' => 'Updated description for Gamma Retail.',
        ]);

        $this->assertSame('Updated description for Gamma Retail.', $updated->description);
    }

    public function testDeleteSoftDeletesTenant(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Omega Supplies'), $this->operator)['tenant'];

        $this->service->delete($this->operator, $tenant->slug);

        $this->assertSoftDeleted('tenants', ['_id' => $tenant->_id], 'landlord');
    }

    public function testRestoreRevivesTenant(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Sigma Education'), $this->operator)['tenant'];

        $this->service->delete($this->operator, $tenant->slug);
        $restored = $this->service->restore($this->operator, $tenant->slug);

        $this->assertFalse($restored->trashed());
    }

    public function testForceDeleteRemovesTenantAndRelations(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Theta Finance'), $this->operator)['tenant'];

        $this->service->delete($this->operator, $tenant->slug);
        $this->service->forceDelete($this->operator, $tenant->slug);

        $this->assertDatabaseMissing('tenants', ['_id' => $tenant->_id], 'landlord');
        $this->assertDatabaseMissing('tenant_role_templates', ['tenant_id' => $tenant->_id], 'landlord');
    }

    /**
     * @return array{name: string, subdomain: string, description?: string}
     */
    private function makeTenantPayload(string $name): array
    {
        return [
            'name' => $name,
            'subdomain' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'description' => $name . ' description',
        ];
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Iota', 'subdomain' => 'tenant-iota'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-iota.test']
        );

        $service->initialize($payload);
    }
}
