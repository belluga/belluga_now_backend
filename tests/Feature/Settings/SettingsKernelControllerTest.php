<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class SettingsKernelControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;
    private AccountUserService $userService;
    private AccountUser $user;

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

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'poi_time_window_days' => [
                    'past' => 1,
                    'future' => 30,
                ],
            ],
            'events' => [
                'default_duration_hours' => 3,
                'mode' => 'basic',
            ],
            'push' => [
                'enabled' => false,
                'throttles' => ['daily' => 100],
                'max_ttl_days' => 7,
            ],
        ]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'events:read',
            'push-settings:update',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser([
            'account-users:view',
            'events:read',
            'push-settings:update',
        ]);

        Sanctum::actingAs($this->user, [
            'account-users:view',
            'events:read',
            'push-settings:update',
        ]);
    }

    public function testSettingsSchemaEndpointReturnsRegisteredNamespaces(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/schema");
        $response->assertStatus(200);

        $response->assertJsonPath('data.schema_version', '1.0.0');

        $namespaces = array_column($response->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertContains('push', $namespaces);
    }

    public function testSettingsValuesEndpointReturnsNamespaceValues(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}settings/values");
        $response->assertStatus(200);

        $response->assertJsonPath('data.map_ui.radius.default_km', 5);
        $response->assertJsonPath('data.events.default_duration_hours', 3);
        $response->assertJsonPath('data.push.max_ttl_days', 7);
    }

    public function testPatchNamespaceAppliesPartialMergeByFieldPresence(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'default_duration_hours' => 4,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 4);

        $values = $this->getJson("{$this->base_api_tenant}settings/values");
        $values->assertStatus(200);
        $values->assertJsonPath('data.events.default_duration_hours', 4);
        $values->assertJsonPath('data.events.mode', 'basic');
    }

    public function testPatchNamespaceRejectsNullForNonNullableField(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'default_duration_hours' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['default_duration_hours']);
    }

    public function testPatchNamespaceAcceptsNamespacedFieldPath(): void
    {
        $response = $this->patchJson("{$this->base_api_tenant}settings/values/events", [
            'events.default_duration_hours' => 6,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.default_duration_hours', 6);
    }

    public function testAbilityFilteringHidesNamespacesAndBlocksPatch(): void
    {
        $restrictedUser = $this->createAccountUser([
            'account-users:view',
            'events:read',
        ]);

        Sanctum::actingAs($restrictedUser, [
            'account-users:view',
            'events:read',
        ]);

        $schema = $this->getJson("{$this->base_api_tenant}settings/schema");
        $schema->assertStatus(200);

        $namespaces = array_column($schema->json('data.namespaces') ?? [], 'namespace');
        $this->assertContains('map_ui', $namespaces);
        $this->assertContains('events', $namespaces);
        $this->assertNotContains('push', $namespaces);

        $patch = $this->patchJson("{$this->base_api_tenant}settings/values/push", [
            'enabled' => true,
        ]);

        $patch->assertStatus(403);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Settings Role ' . uniqid(),
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Settings User',
            'email' => uniqid('settings-user', true) . '@example.org',
            'password' => 'Secret!234',
            'timezone' => 'America/Sao_Paulo',
        ], (string) $role->_id);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);
        $payload = new InitializationPayload(
            landlord: [
                'name' => 'Landlord HQ',
            ],
            tenant: [
                'name' => $this->tenant->name,
                'subdomain' => $this->tenant->subdomain,
            ],
            role: [
                'name' => 'Root',
                'permissions' => ['*'],
            ],
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
            logoSettings: [
                'light_logo_uri' => '/logos/light.png',
            ],
            pwaIcon: [
                'icon192_uri' => '/pwa/icon192.png',
            ],
            tenantDomains: [$this->tenant->slug . '.test'],
        );

        $service->initialize($payload);
    }
}
