<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\ProximityPreference;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class ProfileProximityPreferencesControllerTest extends TestCaseTenant
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

        ProximityPreference::query()->delete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
    }

    public function test_authenticated_identity_can_update_and_read_proximity_preferences(): void
    {
        $user = $this->createRegisteredUser();
        Sanctum::actingAs($user, ['account-users:view']);

        $payload = [
            'max_distance_meters' => 25000,
            'location_preference' => [
                'mode' => 'fixed_reference',
                'fixed_reference' => [
                    'source_kind' => 'manual_coordinate',
                    'coordinate' => [
                        'lat' => -20.6736,
                        'lng' => -40.4976,
                    ],
                    'label' => 'Hotel Base',
                    'entity_namespace' => 'account_profile',
                    'entity_type' => 'hotel',
                    'entity_id' => 'hotel-1',
                ],
            ],
        ];

        $putResponse = $this->putJson(
            "{$this->base_api_tenant}profile/proximity-preferences",
            $payload,
        );

        $putResponse->assertStatus(200);
        $putResponse->assertJsonPath('data.max_distance_meters', 25000);
        $putResponse->assertJsonPath('data.location_preference.mode', 'fixed_reference');
        $putResponse->assertJsonPath(
            'data.location_preference.fixed_reference.coordinate.lat',
            -20.6736,
        );
        $putResponse->assertJsonPath(
            'data.location_preference.fixed_reference.coordinate.lng',
            -40.4976,
        );
        $putResponse->assertJsonPath(
            'data.location_preference.fixed_reference.entity_type',
            'hotel',
        );

        $getResponse = $this->getJson(
            "{$this->base_api_tenant}profile/proximity-preferences",
        );

        $getResponse->assertStatus(200);
        $getResponse->assertJsonPath('data.max_distance_meters', 25000);
        $getResponse->assertJsonPath(
            'data.location_preference.fixed_reference.label',
            'Hotel Base',
        );
    }

    public function test_anonymous_identity_can_update_and_read_proximity_preferences(): void
    {
        $anonymous = AccountUser::create([
            'identity_state' => 'anonymous',
            'fingerprints' => [
                [
                    'hash' => 'anon-hash-profile-proximity',
                ],
            ],
        ]);

        Sanctum::actingAs($anonymous, []);

        $payload = [
            'max_distance_meters' => 12000,
            'location_preference' => [
                'mode' => 'fixed_reference',
                'fixed_reference' => [
                    'source_kind' => 'manual_coordinate',
                    'coordinate' => [
                        'lat' => -21.000001,
                        'lng' => -40.000002,
                    ],
                    'label' => 'Minha base',
                ],
            ],
        ];

        $putResponse = $this->putJson(
            "{$this->base_api_tenant}profile/proximity-preferences",
            $payload,
        );

        $putResponse->assertStatus(200);
        $putResponse->assertJsonPath('data.max_distance_meters', 12000);
        $putResponse->assertJsonPath(
            'data.location_preference.fixed_reference.source_kind',
            'manual_coordinate',
        );

        $stored = ProximityPreference::query()
            ->where('owner_user_id', (string) $anonymous->_id)
            ->firstOrFail();

        $this->assertSame(12000, (int) $stored->max_distance_meters);
        $this->assertSame(
            -21.000001,
            (float) data_get($stored->location_preference, 'fixed_reference.coordinate.lat'),
        );
        $this->assertSame(
            -40.000002,
            (float) data_get($stored->location_preference, 'fixed_reference.coordinate.lng'),
        );
    }

    public function test_live_device_mode_clears_fixed_reference_payload(): void
    {
        $user = $this->createRegisteredUser();
        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->putJson(
            "{$this->base_api_tenant}profile/proximity-preferences",
            [
                'max_distance_meters' => 30000,
                'location_preference' => [
                    'mode' => 'live_device_location',
                    'fixed_reference' => [
                        'source_kind' => 'manual_coordinate',
                        'coordinate' => [
                            'lat' => -20.1,
                            'lng' => -40.1,
                        ],
                    ],
                ],
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.location_preference.mode', 'live_device_location');
        $response->assertJsonPath('data.location_preference.fixed_reference', null);

        $stored = ProximityPreference::query()
            ->where('owner_user_id', (string) $user->_id)
            ->firstOrFail();

        $this->assertNull(data_get($stored->location_preference, 'fixed_reference'));
    }

    public function test_missing_preference_returns_not_found(): void
    {
        $user = $this->createRegisteredUser();
        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->getJson("{$this->base_api_tenant}profile/proximity-preferences");

        $response->assertStatus(404);
    }

    private function createRegisteredUser(): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Profile Proximity Role',
            'permissions' => ['account-users:view'],
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Profile Proximity User',
            'email' => uniqid('profile-proximity', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);
        $service->initialize(new InitializationPayload(
            landlord: ['name' => 'Belluga Solutions'],
            tenant: [
                'name' => 'Belluga Primary',
                'subdomain' => $this->tenant->subdomain,
            ],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: [
                'name' => 'Super Admin',
                'email' => 'admin@belluga.test',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#000000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ["{$this->tenant->subdomain}.{$this->host}"],
        ));
    }
}
