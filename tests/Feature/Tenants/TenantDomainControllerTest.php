<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantDomainControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Tenant $tenantModel;

    private array $headers;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenantModel = Tenant::query()->firstOrFail();
        $this->tenantModel->update([
            'app_domains' => ['tenantkappa.app'],
        ]);
        $this->tenantModel->domains()->updateOrCreate(
            ['path' => 'tenantkappa.test'],
            ['type' => 'web']
        );
        $this->tenantModel = $this->tenantModel->fresh();
        $this->tenantModel->makeCurrent();
        $this->baseUrl = "{$this->base_tenant_api_admin}domains";

        $this->headers = array_merge($this->getHeaders(), [
            'X-App-Domain' => 'tenantkappa.app',
        ]);
    }

    public function test_store_creates_domain(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'path' => 'tenantkappa.com',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'path',
                'type',
                'status',
                'created_at',
            ],
        ]);
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_store_persists_domain_in_the_same_web_domain_source_used_for_tenant_resolution(): void
    {
        $domainPath = 'tenantkappa-route-check.test';

        $storeResponse = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'path' => $domainPath,
        ]);

        $storeResponse->assertCreated();

        $environmentResponse = $this->getJson("http://{$domainPath}/api/v1/environment");

        $environmentResponse->assertOk();
        $environmentResponse->assertJsonPath('type', 'tenant');
        $environmentResponse->assertJsonPath('subdomain', $this->tenantModel->subdomain);
        $environmentResponse->assertJsonPath('domains.0', 'tenantkappa.test');
        $this->assertContains($domainPath, $environmentResponse->json('domains', []));
        $this->assertSame(
            $domainPath,
            parse_url((string) $environmentResponse->json('main_domain'), PHP_URL_HOST)
        );
    }

    public function test_store_rejects_duplicate_domain_for_same_tenant(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'path' => 'TENANTKAPPA.TEST',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['path']);
        $this->assertSame(
            'Domain already exists for this tenant.',
            data_get($response->json(), 'errors.path.0')
        );
    }

    public function test_destroy_soft_deletes_domain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'removekappa.com',
            'type' => 'web',
        ]);
        $response = $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $this->assertSoftDeleted('domains', ['_id' => $domain->_id], 'landlord');
    }

    public function test_restore_brings_back_domain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'restorekappa.com',
            'type' => 'web',
        ]);
        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response = $this->withHeaders($this->headers)
            ->postJson(sprintf('%s/%s/restore', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $response->assertJsonPath('data.path', 'restorekappa.com');
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_force_delete_removes_domain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'forcekappa.com',
            'type' => 'web',
        ]);
        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response = $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s/force-delete', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $this->assertDatabaseMissing('domains', ['_id' => $domain->_id], 'landlord');
    }

    public function test_index_lists_only_active_web_domains_with_pagination_order(): void
    {
        $this->tenantModel->domains()
            ->withTrashed()
            ->get()
            ->each(static function ($domain): void {
                $domain->forceDelete();
            });

        Carbon::setTestNow(Carbon::parse('2026-05-01T10:00:00Z'));
        $this->tenantModel->domains()->create([
            'path' => 'active-old.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-02T10:00:00Z'));
        $this->tenantModel->domains()->create([
            'path' => 'active-new.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-03T10:00:00Z'));
        $deletedEarlier = $this->tenantModel->domains()->create([
            'path' => 'deleted-earlier.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        $deletedEarlier->delete();

        Carbon::setTestNow(Carbon::parse('2026-05-04T10:00:00Z'));
        $deletedLater = $this->tenantModel->domains()->create([
            'path' => 'deleted-later.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        $deletedLater->delete();

        $this->tenantModel->domains()->create([
            'path' => 'android-ignored.example.com',
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
        ]);

        Carbon::setTestNow();

        $response = $this->withHeaders($this->headers)
            ->getJson("{$this->baseUrl}?page=1&per_page=4");

        $response->assertOk();
        $response->assertJsonPath('current_page', 1);
        $response->assertJsonPath('per_page', 4);
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('last_page', 1);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.path', 'active-new.example.com');
        $response->assertJsonPath('data.0.status', 'active');
        $response->assertJsonPath('data.1.path', 'active-old.example.com');
        $response->assertJsonPath('data.1.status', 'active');
        $this->assertFalse(collect($response->json('data'))->contains(
            static fn (array $domain): bool => ($domain['path'] ?? null) === 'deleted-earlier.example.com'
        ));
        $this->assertFalse(collect($response->json('data'))->contains(
            static fn (array $domain): bool => ($domain['path'] ?? null) === 'deleted-later.example.com'
        ));
        $this->assertFalse(collect($response->json('data'))->contains(
            static fn (array $domain): bool => ($domain['path'] ?? null) === 'android-ignored.example.com'
        ));
    }

    public function test_index_uses_stable_id_tie_break_for_matching_created_at(): void
    {
        $this->tenantModel->domains()
            ->withTrashed()
            ->get()
            ->each(static function ($domain): void {
                $domain->forceDelete();
            });

        Carbon::setTestNow(Carbon::parse('2026-05-05T10:00:00Z'));
        $this->tenantModel->domains()->create([
            'path' => 'same-time-first.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        $this->tenantModel->domains()->create([
            'path' => 'same-time-second.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        Carbon::setTestNow();

        $response = $this->withHeaders($this->headers)
            ->getJson("{$this->baseUrl}?page=1&per_page=2");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.path', 'same-time-second.example.com');
        $response->assertJsonPath('data.1.path', 'same-time-first.example.com');
    }

    public function test_index_clamps_per_page_to_safe_maximum(): void
    {
        $response = $this->withHeaders($this->headers)
            ->getJson("{$this->baseUrl}?page=1&per_page=999");

        $response->assertOk();
        $response->assertJsonPath('per_page', 100);
    }

    public function test_index_forbidden_without_read_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['tenant-domains:update']);

        $response = $this->withHeaders([
            'X-App-Domain' => 'tenantkappa.app',
        ])->getJson($this->baseUrl);

        $response->assertStatus(403);
    }

    public function test_borrowed_token_read_ability_cannot_read_current_tenant_domains(): void
    {
        $otherTenant = $this->createSecondaryTenant();
        $headers = $this->authHeaders($this->createLandlordPrincipalForTenantRoles(
            email: 'borrowed-read@domains.test',
            tenantRoles: [
                [
                    'tenant' => $this->tenantModel,
                    'permissions' => ['tenant-domains:read'],
                ],
                [
                    'tenant' => $otherTenant,
                    'permissions' => ['tenant-domains:update'],
                ],
            ],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)
            ->getJson($this->domainsBaseUrl($otherTenant));

        $response->assertStatus(403);
    }

    public function test_store_forbidden_without_update_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['tenant-domains:read']);

        $response = $this->withHeaders([
            'X-App-Domain' => 'tenantkappa.app',
        ])->postJson($this->baseUrl, [
            'path' => 'blocked-write.example.com',
        ]);

        $response->assertStatus(403);
    }

    public function test_borrowed_token_update_ability_cannot_mutate_current_tenant_domains(): void
    {
        $otherTenant = $this->createSecondaryTenant();
        $headers = $this->authHeaders($this->createLandlordPrincipalForTenantRoles(
            email: 'borrowed-update@domains.test',
            tenantRoles: [
                [
                    'tenant' => $this->tenantModel,
                    'permissions' => ['tenant-domains:update'],
                ],
                [
                    'tenant' => $otherTenant,
                    'permissions' => ['tenant-domains:read'],
                ],
            ],
        ), ['tenant-domains:update']);

        $blockedPath = 'borrowed-write-blocked.example.com';
        $this->withHeaders($headers)
            ->postJson($this->domainsBaseUrl($otherTenant), [
                'path' => $blockedPath,
            ])
            ->assertStatus(403);
        $this->assertFalse(
            $otherTenant->fresh()->domains()->withTrashed()->where('path', $blockedPath)->exists()
        );

        $domain = $otherTenant->domains()->create([
            'path' => 'borrowed-delete-blocked.example.com',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
        $beforeDeletedAt = $domain->deleted_at?->toJSON();

        $this->withHeaders($headers)
            ->deleteJson(sprintf('%s/%s', $this->domainsBaseUrl($otherTenant), $domain->_id))
            ->assertStatus(403);

        $domain = $domain->fresh();
        $this->assertSame($beforeDeletedAt, $domain->deleted_at?->toJSON());
        $this->assertFalse($domain->trashed());
    }

    public function test_index_accepts_token_from_tenant_admin_login_flow(): void
    {
        $tenantHost = "{$this->tenant->subdomain}.{$this->host}";
        $login = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => 'root@example.org',
                'password' => 'Secret!234',
                'device_name' => 'tenant-domain-index-check',
            ]
        );

        $login->assertStatus(200);
        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'X-App-Domain' => 'tenantkappa.app',
        ])->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJsonPath('data.0.status', 'active');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Kappa', 'subdomain' => 'tenant-kappa'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenantkappa.test']
        );

        $service->initialize($payload);
    }

    /**
     * @param  array<int, array{tenant: Tenant, permissions: array<int, string>}>  $tenantRoles
     */
    private function createLandlordPrincipalForTenantRoles(
        string $email,
        array $tenantRoles
    ): LandlordUser {
        $user = LandlordUser::create([
            'name' => str_replace('@domains.test', '', $email),
            'emails' => [$email],
            'identity_state' => 'registered',
        ]);

        $user->tenant_roles = array_map(
            static fn (array $role): array => [
                'name' => 'Tenant Domain Manager',
                'slug' => 'tenant-domain-manager',
                'permissions' => $role['permissions'],
                'tenant_id' => (string) $role['tenant']->_id,
            ],
            $tenantRoles
        );
        $user->save();

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $tokenAbilities
     * @return array<string, string>
     */
    private function authHeaders(LandlordUser $user, array $tokenAbilities): array
    {
        $token = $user
            ->createToken('tenant-domain-test', $tokenAbilities)
            ->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];
    }

    private function createSecondaryTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', 'tenant-domain-secondary')
            ->first();

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::create([
                'name' => 'Tenant Domain Secondary',
                'subdomain' => 'tenant-domain-secondary',
                'app_domains' => ['tenant-domain-secondary.app'],
            ]);
        }

        $tenant->domains()
            ->withTrashed()
            ->get()
            ->each(static function ($domain): void {
                $domain->forceDelete();
            });
        $tenant->domains()->create([
            'path' => 'tenant-domain-secondary.test',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);

        return $tenant->fresh();
    }

    private function domainsBaseUrl(Tenant $tenant): string
    {
        return sprintf(
            'http://%s.%s/admin/api/v1/domains',
            $tenant->subdomain,
            $this->host
        );
    }
}
