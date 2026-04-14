<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantBrandingManagementService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantBrandingManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantBrandingManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();

        $this->service = $this->app->make(TenantBrandingManagementService::class);
    }

    public function test_update_creates_branding_data_when_empty(): void
    {
        $payload = [
            'logo_settings' => [
                'light_logo_uri' => 'https://cdn.example/light.svg',
                'dark_logo_uri' => 'https://cdn.example/dark.svg',
            ],
            'theme_data_settings' => [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#dddddd',
            ],
        ];

        $branding = $this->service->update($this->tenant, $payload);

        $this->assertSame(
            'https://cdn.example/light.svg',
            $branding['logo_settings']['light_logo_uri']
        );
        $this->assertSame('#ffffff', $branding['theme_data_settings']['primary_seed_color']);
    }

    public function test_update_does_not_overwrite_with_empty_values(): void
    {
        $this->tenant->branding_data = [
            'logo_settings' => [
                'light_logo_uri' => 'https://existing/light.svg',
                'dark_logo_uri' => 'https://existing/dark.svg',
            ],
            'theme_data_settings' => [
                'brightness_default' => 'dark',
                'primary_seed_color' => '#abcdef',
                'secondary_seed_color' => '#654321',
            ],
            'pwa_icon' => [
                'source_uri' => 'https://existing/pwa.png',
                'icon192_uri' => 'https://existing/icon-192.png',
                'icon512_uri' => 'https://existing/icon-512.png',
                'icon_maskable512_uri' => 'https://existing/icon-maskable.png',
            ],
        ];
        $this->tenant->save();

        $payload = [
            'logo_settings' => [
                'light_logo_uri' => '',
                'dark_logo_uri' => 'https://cdn.example/new-dark.svg',
            ],
            'theme_data_settings' => [
                'primary_seed_color' => '',
                'secondary_seed_color' => '#000000',
            ],
        ];

        $branding = $this->service->update($this->tenant->fresh(), $payload);

        $this->assertSame(
            'https://existing/light.svg',
            $branding['logo_settings']['light_logo_uri']
        );
        $this->assertSame(
            'https://cdn.example/new-dark.svg',
            $branding['logo_settings']['dark_logo_uri']
        );
        $this->assertSame(
            '#000000',
            $branding['theme_data_settings']['secondary_seed_color']
        );
    }

    public function test_update_applies_uploaded_logo_urls(): void
    {
        $branding = $this->service->update(
            $this->tenant,
            ['logo_settings' => []],
            ['dark_icon_uri' => 'https://cdn.example/dark-icon.png']
        );

        $this->assertSame(
            'https://cdn.example/dark-icon.png',
            $branding['logo_settings']['dark_icon_uri']
        );
    }

    public function test_update_normalizes_missing_logo_keys_when_existing_branding_is_partial(): void
    {
        $this->tenant->branding_data = [
            'logo_settings' => [
                'light_logo_uri' => 'https://existing/light.svg',
            ],
            'theme_data_settings' => [
                'brightness_default' => 'light',
            ],
        ];
        $this->tenant->save();

        $branding = $this->service->update(
            $this->tenant->fresh(),
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

    public function test_update_includes_pwa_variants(): void
    {
        $variants = [
            'source_uri' => 'https://cdn.example/pwa.png',
            'icon192_uri' => 'https://cdn.example/pwa-192.png',
        ];

        $branding = $this->service->update(
            $this->tenant,
            ['logo_settings' => []],
            [],
            $variants
        );

        $this->assertSame('https://cdn.example/pwa.png', $branding['pwa_icon']['source_uri']);
        $this->assertSame('https://cdn.example/pwa-192.png', $branding['pwa_icon']['icon192_uri']);
    }

    public function test_update_persists_tenant_name_and_keeps_slug_stable(): void
    {
        $originalSlug = (string) $this->tenant->slug;

        $this->service->update(
            $this->tenant,
            [
                'name' => 'Guarappari',
                'logo_settings' => [],
                'theme_data_settings' => [],
            ]
        );

        $freshTenant = $this->tenant->fresh();

        $this->assertSame('Guarappari', $freshTenant?->name);
        $this->assertSame('Guarappari', $freshTenant?->short_name);
        $this->assertSame($originalSlug, $freshTenant?->slug);
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
