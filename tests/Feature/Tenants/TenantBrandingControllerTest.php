<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantBrandingControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountUser $operator;

    private AccountUserService $userService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class);

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole(['tenant-branding:update']);
        $this->account->makeCurrent();

        $this->userService = $this->app->make(AccountUserService::class);

        $this->operator = $this->userService->create($this->account, [
            'name' => 'Branding Operator',
            'email' => 'branding-operator@example.org',
            'password' => 'Secret!234',
        ], (string) $this->role->_id);

        Sanctum::actingAs($this->operator, ['tenant-branding:update'], 'sanctum');
    }

    public function testUpdatePersistsBrandingData(): void
    {
        $payload = [
            'theme_data_settings' => [
                'light_scheme_data' => [
                    'primary_seed_color' => '#ffffff',
                    'secondary_seed_color' => '#eeeeee',
                ],
            ],
        ];

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-sigma.test'])
            ->postJson('api/v1/branding/update', $payload);

        $response->assertOk();
        $tenant = Tenant::query()->first()->fresh();
        $this->assertSame(
            '#ffffff',
            $tenant->branding_data['theme_data_settings']['light_scheme_data']['primary_seed_color']
        );
    }

    public function testUpdateStoresUploadedLogos(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('light_logo.png', 120, 40);

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-sigma.test'])
            ->post('api/v1/branding/update', [
                'logo_settings' => [
                    'light_logo_uri' => $file,
                ],
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('branding_data.logo_settings.light_logo_uri'));
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Sigma', 'subdomain' => 'tenant-sigma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-sigma.test']
        );

        $service->initialize($payload);
    }
}
