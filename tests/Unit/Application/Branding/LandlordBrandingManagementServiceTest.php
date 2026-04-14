<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Branding;

use App\Application\Branding\LandlordBrandingManagementService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Landlord;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordBrandingManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Landlord $landlordModel;

    private LandlordBrandingManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->landlordModel = Landlord::singleton();
        $this->service = $this->app->make(LandlordBrandingManagementService::class);
    }

    public function test_update_normalizes_missing_logo_keys_when_existing_branding_is_partial(): void
    {
        $this->landlordModel->branding_data = [
            'logo_settings' => [
                'light_logo_uri' => 'https://existing/light.svg',
            ],
            'theme_data_settings' => [
                'brightness_default' => 'light',
            ],
        ];
        $this->landlordModel->save();

        $branding = $this->service->update(
            $this->landlordModel->fresh(),
            [
                'theme_data_settings' => [
                    'primary_seed_color' => '#ffffff',
                ],
            ]
        );

        $this->assertSame(
            'https://existing/light.svg',
            $branding['logo_settings']['light_logo_uri']
        );
        $this->assertArrayHasKey('light_icon_uri', $branding['logo_settings']);
        $this->assertArrayHasKey('dark_icon_uri', $branding['logo_settings']);
        $this->assertArrayHasKey('favicon_uri', $branding['logo_settings']);
        $this->assertSame('', $branding['logo_settings']['light_icon_uri']);
        $this->assertSame('', $branding['logo_settings']['dark_icon_uri']);
        $this->assertSame('', $branding['logo_settings']['favicon_uri']);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Lambda', 'subdomain' => 'tenant-lambda'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-lambda.test']
        );

        $service->initialize($payload);
    }
}
