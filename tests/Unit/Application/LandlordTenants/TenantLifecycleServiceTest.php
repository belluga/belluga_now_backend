<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordTenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\LandlordTenants\TenantLifecycleService;
use App\Jobs\Environment\RebuildTenantEnvironmentSnapshotJob;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Support\Facades\Queue;
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

    public function test_paginate_returns_accessible_tenants(): void
    {
        $paginator = $this->service->paginate($this->operator, false, 15);

        $this->assertGreaterThanOrEqual(1, $paginator->total());

        $ids = collect($paginator->items())->pluck('id')->all();
        $firstTenantId = (string) Tenant::query()->firstOrFail()->_id;

        $this->assertContains($firstTenantId, $ids);
    }

    public function test_paginate_uses_first_related_main_domain_when_no_main_flag_exists(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Lambda Academy'), $this->operator)['tenant'];
        $aliasDomain = 'alias-only-'.Str::lower(Str::random(8)).'.example.test';

        $tenant->domains()->delete();
        $tenant->domains()->create([
            'type' => 'web',
            'path' => $aliasDomain,
        ]);

        $paginator = $this->service->paginate($this->operator, false, 50);
        $item = collect($paginator->items())
            ->first(fn (array $entry): bool => $entry['id'] === (string) $tenant->_id);

        $this->assertNotNull($item);
        $this->assertSame(
            'https://'.$aliasDomain,
            $item['main_domain']
        );
    }

    public function test_paginate_ignores_legacy_persisted_landlord_fallback_domains(): void
    {
        $tenant = $this->service->create([
            ...$this->makeTenantPayload('Guarapari Tenant'),
            'subdomain' => 'guarappari',
        ], $this->operator)['tenant'];
        $rootHost = $this->rootHost();

        $tenant->domains()->delete();
        $tenant->domains()->create([
            'type' => 'web',
            'path' => "guarapari.$rootHost",
        ]);

        $paginator = $this->service->paginate($this->operator, false, 50);
        $item = collect($paginator->items())
            ->first(fn (array $entry): bool => $entry['id'] === (string) $tenant->_id);

        $this->assertNotNull($item);
        $this->assertSame(
            "https://guarappari.$rootHost",
            $item['main_domain']
        );
        $this->assertSame(
            ["guarappari.$rootHost"],
            $item['domains']
        );
    }

    public function test_create_persists_tenant_and_assigns_role_to_operator(): void
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

    public function test_create_does_not_persist_implicit_fallback_domain(): void
    {
        $payload = $this->makeTenantPayload('No Domain Seed');

        $tenant = $this->service->create($payload, $this->operator)['tenant'];
        $rootHost = $this->rootHost();

        $this->assertCount(0, $tenant->domains()->get());
        $this->assertSame(
            "https://{$tenant->subdomain}.$rootHost",
            $tenant->getMainDomain()
        );
        $this->assertSame(
            ["{$tenant->subdomain}.$rootHost"],
            $tenant->resolvedDomains()
        );
    }

    public function test_update_mutates_tenant_attributes(): void
    {
        $payload = $this->makeTenantPayload('Gamma Retail');
        $tenant = $this->service->create($payload, $this->operator)['tenant'];

        $updated = $this->service->update($tenant, [
            'description' => 'Updated description for Gamma Retail.',
        ]);

        $this->assertSame('Updated description for Gamma Retail.', $updated->description);
    }

    public function test_update_dispatches_environment_snapshot_refresh_without_current_tenant_context(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Snapshot Delta'), $this->operator)['tenant'];

        Queue::fake();

        $this->service->update($tenant, [
            'name' => 'Snapshot Delta Updated',
        ]);

        Queue::assertPushed(RebuildTenantEnvironmentSnapshotJob::class);
    }

    public function test_delete_soft_deletes_tenant(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Omega Supplies'), $this->operator)['tenant'];

        $this->service->delete($this->operator, $tenant->slug);

        $this->assertSoftDeleted('tenants', ['_id' => $tenant->_id], 'landlord');
    }

    public function test_restore_revives_tenant(): void
    {
        $tenant = $this->service->create($this->makeTenantPayload('Sigma Education'), $this->operator)['tenant'];

        $this->service->delete($this->operator, $tenant->slug);
        $restored = $this->service->restore($this->operator, $tenant->slug);

        $this->assertFalse($restored->trashed());
    }

    public function test_force_delete_removes_tenant_and_relations(): void
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
            'subdomain' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => $name.' description',
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
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-iota.test']
        );

        $service->initialize($payload);
    }

    private function rootHost(): string
    {
        $configuredUrl = (string) config('app.url');
        $rootHost = parse_url($configuredUrl, PHP_URL_HOST);
        if (is_string($rootHost) && $rootHost !== '') {
            return $rootHost;
        }

        return trim(str_replace(['https://', 'http://'], '', $configuredUrl), '/');
    }
}
