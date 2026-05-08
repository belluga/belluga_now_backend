<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Facades\Hash;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Tenant $tenantModel;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantModel = Tenant::query()->firstOrFail();
        $this->tenantModel->update(['app_domains' => []]);
        $this->deleteTypedAppDomains($this->tenantModel);
        $this->tenantModel->domains()->updateOrCreate(
            ['path' => 'tenant-app-domain.test'],
            ['type' => Tenant::DOMAIN_TYPE_WEB]
        );
        $this->tenantModel->domains()->create([
            'path' => 'com.tenant.initial',
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
        ]);
        $this->tenantModel->domains()->create([
            'path' => 'com.tenant.initial.ios',
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
        ]);
        $this->tenantModel = $this->tenantModel->fresh();
        $this->tenantModel->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'sha256_cert_fingerprints' => [
                        '3e:72:4c:54:e9:53:26:7d:e6:e1:9b:f8:dc:53:30:2a:08:01:8e:36:40:4d:0c:ca:98:3b:46:84:53:e7:a9:a9',
                    ],
                ],
                'ios' => [
                    'team_id' => 'ABCDE12345',
                    'paths' => ['/invite*', '/convites*'],
                ],
            ],
        ]);

        $this->baseUrl = "{$this->base_tenant_api_admin}appdomains";
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson($this->baseUrl);

        $response->assertStatus(401);
    }

    public function test_index_forbidden_without_tenant_access(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'no-tenant-access@appdomains.test',
            tenant: null,
            rolePermissions: ['tenant-domains:read'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)->getJson($this->baseUrl);

        $response->assertStatus(403);
    }

    public function test_index_forbidden_without_read_ability(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'missing-read@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:read'],
        ), ['tenant-domains:update']);

        $response = $this->withHeaders($headers)->getJson($this->baseUrl);

        $response->assertStatus(403);
    }

    public function test_borrowed_token_read_ability_cannot_read_current_tenant_app_domains(): void
    {
        $otherTenant = $this->createSecondaryTenant();
        $headers = $this->authHeaders($this->createLandlordPrincipalForTenantRoles(
            email: 'borrowed-read@appdomains.test',
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
        $otherTenantBaseUrl = sprintf(
            'http://%s.%s/admin/api/v1/appdomains',
            $otherTenant->subdomain,
            $this->host
        );

        $this->withHeaders($headers)
            ->getJson($otherTenantBaseUrl)
            ->assertStatus(403);
    }

    public function test_store_forbidden_without_update_ability(): void
    {
        $beforeDomains = $this->appDomainState($this->tenantModel);
        $beforeAssetLinks = $this->assetLinksPayload($this->tenantModel);
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'missing-update@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)->postJson($this->baseUrl, [
            'platform' => 'android',
            'identifier' => 'com.tenant.blocked',
        ]);

        $response->assertStatus(403);
        $this->assertSame($beforeDomains, $this->appDomainState($this->tenantModel));
        $this->assertSame($beforeAssetLinks, $this->assetLinksPayload($this->tenantModel));
    }

    public function test_store_ios_forbidden_without_update_ability_does_not_mutate_apple_association(): void
    {
        $beforeDomains = $this->appDomainState($this->tenantModel);
        $beforeAppleAssociation = $this->appleAssociationPayload($this->tenantModel);
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'missing-ios-store@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)->postJson($this->baseUrl, [
            'platform' => 'ios',
            'identifier' => 'com.tenant.blocked.ios',
        ]);

        $response->assertStatus(403);
        $this->assertSame($beforeDomains, $this->appDomainState($this->tenantModel));
        $this->assertSame($beforeAppleAssociation, $this->appleAssociationPayload($this->tenantModel));
    }

    public function test_delete_forbidden_without_update_ability(): void
    {
        $beforeDomains = $this->appDomainState($this->tenantModel);
        $beforeAssetLinks = $this->assetLinksPayload($this->tenantModel);
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'missing-delete@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)->deleteJson($this->baseUrl, [
            'platform' => 'android',
        ]);

        $response->assertStatus(403);
        $this->assertSame($beforeDomains, $this->appDomainState($this->tenantModel));
        $this->assertSame($beforeAssetLinks, $this->assetLinksPayload($this->tenantModel));
    }

    public function test_delete_ios_forbidden_without_update_ability_does_not_mutate_apple_association(): void
    {
        $beforeDomains = $this->appDomainState($this->tenantModel);
        $beforeAppleAssociation = $this->appleAssociationPayload($this->tenantModel);
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'missing-ios-delete@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)->deleteJson($this->baseUrl, [
            'platform' => 'ios',
        ]);

        $response->assertStatus(403);
        $this->assertSame($beforeDomains, $this->appDomainState($this->tenantModel));
        $this->assertSame($beforeAppleAssociation, $this->appleAssociationPayload($this->tenantModel));
    }

    public function test_wrong_tenant_principal_cannot_read_or_mutate_app_domains(): void
    {
        $otherTenant = $this->createSecondaryTenant();
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'wrong-tenant@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:read', 'tenant-domains:update'],
        ), ['tenant-domains:read', 'tenant-domains:update']);
        $otherTenantBaseUrl = sprintf(
            'http://%s.%s/admin/api/v1/appdomains',
            $otherTenant->subdomain,
            $this->host
        );

        $this->withHeaders($headers)
            ->getJson($otherTenantBaseUrl)
            ->assertStatus(403);

        $beforePostDomains = $this->appDomainState($otherTenant);
        $beforePostAssetLinks = $this->assetLinksPayload($otherTenant);
        $this->withHeaders($headers)
            ->postJson($otherTenantBaseUrl, [
                'platform' => 'android',
                'identifier' => 'com.wrong.tenant.write',
            ])
            ->assertStatus(403);
        $this->assertSame($beforePostDomains, $this->appDomainState($otherTenant));
        $this->assertSame($beforePostAssetLinks, $this->assetLinksPayload($otherTenant));

        $beforeDeleteDomains = $this->appDomainState($otherTenant);
        $beforeDeleteAssetLinks = $this->assetLinksPayload($otherTenant);
        $this->withHeaders($headers)
            ->deleteJson($otherTenantBaseUrl, [
                'platform' => 'android',
            ])
            ->assertStatus(403);
        $this->assertSame($beforeDeleteDomains, $this->appDomainState($otherTenant));
        $this->assertSame($beforeDeleteAssetLinks, $this->assetLinksPayload($otherTenant));
    }

    public function test_borrowed_token_update_ability_cannot_mutate_current_tenant_app_domains(): void
    {
        $otherTenant = $this->createSecondaryTenant();
        $headers = $this->authHeaders($this->createLandlordPrincipalForTenantRoles(
            email: 'borrowed-update@appdomains.test',
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
        $otherTenantBaseUrl = sprintf(
            'http://%s.%s/admin/api/v1/appdomains',
            $otherTenant->subdomain,
            $this->host
        );

        $beforePostDomains = $this->appDomainState($otherTenant);
        $beforePostAssetLinks = $this->assetLinksPayload($otherTenant);
        $this->withHeaders($headers)
            ->postJson($otherTenantBaseUrl, [
                'platform' => 'android',
                'identifier' => 'com.borrowed.tenant.write',
            ])
            ->assertStatus(403);
        $this->assertSame($beforePostDomains, $this->appDomainState($otherTenant));
        $this->assertSame($beforePostAssetLinks, $this->assetLinksPayload($otherTenant));

        $beforeDeleteDomains = $this->appDomainState($otherTenant);
        $beforeDeleteAssetLinks = $this->assetLinksPayload($otherTenant);
        $this->withHeaders($headers)
            ->deleteJson($otherTenantBaseUrl, [
                'platform' => 'android',
            ])
            ->assertStatus(403);
        $this->assertSame($beforeDeleteDomains, $this->appDomainState($otherTenant));
        $this->assertSame($beforeDeleteAssetLinks, $this->assetLinksPayload($otherTenant));
    }

    public function test_index_accepts_token_from_tenant_admin_login_flow(): void
    {
        $loginUser = $this->createLandlordPrincipal(
            email: 'login-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:read']
        );
        $tenantHost = "{$this->tenant->subdomain}.{$this->host}";

        $login = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => $loginUser->emails[0],
                'password' => 'Secret!234',
                'device_name' => 'tenant-app-domain-index',
            ]
        );

        $login->assertOk();
        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJsonPath('app_domains.android', 'com.tenant.initial');
        $response->assertJsonPath('app_domains.ios', 'com.tenant.initial.ios');
    }

    public function test_store_and_delete_accept_tenant_admin_login_token_with_update_role(): void
    {
        $loginUser = $this->createLandlordPrincipal(
            email: 'login-update-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update']
        );
        $tenantHost = "{$this->tenant->subdomain}.{$this->host}";

        $login = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => $loginUser->emails[0],
                'password' => 'Secret!234',
                'device_name' => 'tenant-app-domain-mutation',
            ]
        );

        $login->assertOk();
        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);
        $loginHeaders = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];

        $this->withHeaders($loginHeaders)
            ->postJson($this->baseUrl, [
                'platform' => 'android',
                'identifier' => 'com.tenant.login.authorized',
            ])
            ->assertOk()
            ->assertJsonPath('app_domains.android', 'com.tenant.login.authorized');

        $this->withHeaders($loginHeaders)
            ->deleteJson($this->baseUrl, [
                'platform' => 'android',
            ])
            ->assertOk()
            ->assertJsonPath('app_domains.android', null);
    }

    public function test_store_allows_authorized_domain_manager_and_preserves_app_link_payload(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'store-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:update']);

        $response = $this->withHeaders($headers)->postJson($this->baseUrl, [
            'platform' => 'android',
            'identifier' => 'com.tenant.authorized',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier saved successfully.');
        $response->assertJsonPath('app_domains.android', 'com.tenant.authorized');
        $response->assertJsonPath('app_domains.ios', 'com.tenant.initial.ios');

        $assetLinks = $this->get("{$this->base_tenant_url}.well-known/assetlinks.json");
        $assetLinks->assertOk();
        $assetLinks->assertJsonPath('0.relation.0', 'delegate_permission/common.handle_all_urls');
        $assetLinks->assertJsonPath('0.target.namespace', 'android_app');
        $assetLinks->assertJsonPath('0.target.package_name', 'com.tenant.authorized');
        $assetLinks->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9'
        );
    }

    public function test_delete_allows_authorized_domain_manager_and_updates_app_link_payload(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'delete-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:update']);

        $response = $this->withHeaders($headers)->deleteJson($this->baseUrl, [
            'platform' => 'android',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier removed successfully.');
        $response->assertJsonPath('app_domains.android', null);
        $response->assertJsonPath('app_domains.ios', 'com.tenant.initial.ios');

        $assetLinks = $this->get("{$this->base_tenant_url}.well-known/assetlinks.json");
        $assetLinks->assertOk();
        $assetLinks->assertExactJson([]);
    }

    public function test_store_allows_authorized_domain_manager_and_updates_apple_association_payload(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'store-ios-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:update']);

        $response = $this->withHeaders($headers)->postJson($this->baseUrl, [
            'platform' => 'ios',
            'identifier' => 'com.tenant.authorized.ios',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier saved successfully.');
        $response->assertJsonPath('app_domains.android', 'com.tenant.initial');
        $response->assertJsonPath('app_domains.ios', 'com.tenant.authorized.ios');

        $appleAssociation = $this->get("{$this->base_tenant_url}.well-known/apple-app-site-association");
        $appleAssociation->assertOk();
        $appleAssociation->assertJsonPath('applinks.apps', []);
        $appleAssociation->assertJsonPath('applinks.details.0.appID', 'ABCDE12345.com.tenant.authorized.ios');
        $appleAssociation->assertJsonPath('applinks.details.0.paths.0', '/invite*');
        $appleAssociation->assertJsonPath('applinks.details.0.paths.1', '/convites*');
    }

    public function test_delete_allows_authorized_domain_manager_and_updates_apple_association_payload(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'delete-ios-appdomain@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:update'],
        ), ['tenant-domains:update']);

        $response = $this->withHeaders($headers)->deleteJson($this->baseUrl, [
            'platform' => 'ios',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'App domain identifier removed successfully.');
        $response->assertJsonPath('app_domains.android', 'com.tenant.initial');
        $response->assertJsonPath('app_domains.ios', null);

        $appleAssociation = $this->get("{$this->base_tenant_url}.well-known/apple-app-site-association");
        $appleAssociation->assertOk();
        $appleAssociation->assertJsonPath('applinks.apps', []);
        $appleAssociation->assertJsonPath('applinks.details', []);
    }

    public function test_tenant_app_domain_routes_are_not_registered_on_landlord_domain(): void
    {
        $headers = $this->authHeaders($this->createLandlordPrincipal(
            email: 'landlord-route-matrix@appdomains.test',
            tenant: $this->tenantModel,
            rolePermissions: ['tenant-domains:read'],
        ), ['tenant-domains:read']);

        $response = $this->withHeaders($headers)
            ->getJson("http://{$this->host}/admin/api/v1/appdomains");

        $response->assertStatus(404);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant App Domain', 'subdomain' => 'tenant-app-domain'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@appdomains.test', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-app-domain.test']
        );

        $service->initialize($payload);
    }

    /**
     * @param  array<int, string>  $rolePermissions
     */
    private function createLandlordPrincipal(
        string $email,
        ?Tenant $tenant,
        array $rolePermissions
    ): LandlordUser {
        return $this->createLandlordPrincipalForTenantRoles(
            email: $email,
            tenantRoles: $tenant instanceof Tenant
                ? [[
                    'tenant' => $tenant,
                    'permissions' => $rolePermissions,
                ]]
                : []
        );
    }

    /**
     * @param  array<int, array{tenant: Tenant, permissions: array<int, string>}>  $tenantRoles
     */
    private function createLandlordPrincipalForTenantRoles(
        string $email,
        array $tenantRoles
    ): LandlordUser {
        $passwordHash = Hash::make('Secret!234');
        $user = LandlordUser::create([
            'name' => str_replace('@appdomains.test', '', $email),
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
        $this->syncPasswordCredentialForPrincipal($user, $passwordHash);

        return $user->fresh();
    }

    private function syncPasswordCredentialForPrincipal(LandlordUser $user, string $passwordHash): void
    {
        /** @var LandlordUserAccessService $accessService */
        $accessService = $this->app->make(LandlordUserAccessService::class);

        if (! method_exists($accessService, 'syncPasswordCredentialsForEmails')
            || ! method_exists($accessService, 'removeLegacyPasswordState')) {
            throw new \RuntimeException('Canonical landlord password credential sync is unavailable.');
        }

        $accessService->syncPasswordCredentialsForEmails($user, $passwordHash);
        $accessService->removeLegacyPasswordState($user);
    }

    /**
     * @param  array<int, string>  $tokenAbilities
     * @return array<string, string>
     */
    private function authHeaders(LandlordUser $user, array $tokenAbilities): array
    {
        $token = $user
            ->createToken('tenant-app-domain-test', $tokenAbilities)
            ->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];
    }

    private function createSecondaryTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', 'tenant-app-domain-secondary')
            ->first();

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::create([
                'name' => 'Tenant App Domain Secondary',
                'subdomain' => 'tenant-app-domain-secondary',
                'app_domains' => [],
            ]);
        }

        $this->deleteTypedAppDomains($tenant);
        $tenant->domains()->updateOrCreate(
            ['path' => 'tenant-app-domain-secondary.test'],
            ['type' => Tenant::DOMAIN_TYPE_WEB]
        );
        $tenant->domains()->create([
            'path' => 'com.secondary.initial',
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
        ]);
        $tenant->domains()->create([
            'path' => 'com.secondary.initial.ios',
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
        ]);

        return $tenant->fresh();
    }

    /**
     * @return array{android: ?string, ios: ?string}
     */
    private function appDomainState(Tenant $tenant): array
    {
        return $tenant->fresh()->typedAppDomainIdentifiers();
    }

    /**
     * @return array<int, mixed>
     */
    private function assetLinksPayload(Tenant $tenant): array
    {
        $response = $this->get($this->tenantBaseUrl($tenant).'.well-known/assetlinks.json');
        $response->assertOk();

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function appleAssociationPayload(Tenant $tenant): array
    {
        $response = $this->get($this->tenantBaseUrl($tenant).'.well-known/apple-app-site-association');
        $response->assertOk();

        return $response->json();
    }

    private function tenantBaseUrl(Tenant $tenant): string
    {
        return "http://{$tenant->subdomain}.{$this->host}/";
    }

    private function deleteTypedAppDomains(Tenant $tenant): void
    {
        $tenant->domains()
            ->withTrashed()
            ->whereIn('type', [Tenant::DOMAIN_TYPE_APP_ANDROID, Tenant::DOMAIN_TYPE_APP_IOS])
            ->get()
            ->each(static function ($domain): void {
                $domain->forceDelete();
            });
    }
}
