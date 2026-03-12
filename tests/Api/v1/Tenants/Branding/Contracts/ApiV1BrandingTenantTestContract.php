<?php

namespace Tests\Api\v1\Tenants\Branding\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseTenant;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;

abstract class ApiV1BrandingTenantTestContract extends TestCaseTenant
{
    private bool $tenantBrandingMutated = false;

    protected function tearDown(): void
    {
        if ($this->tenantBrandingMutated) {
            $this->resetTenantBrandingToDefaults();
            $this->tenantBrandingMutated = false;
        }

        parent::tearDown();
    }

    public function testDefaultBranding()
    {
        $response = $this->_getBranding();
        $response->assertStatus(200);

        $resultData = [
            'brightness_default' => $response->json()['theme_data_settings']['brightness_default'],
            'primary_seed_color' => $response->json()['theme_data_settings']['primary_seed_color'],
            'secondary_seed_color' => $response->json()['theme_data_settings']['secondary_seed_color'],
        ];

        $check_values = [
            'brightness_default' => 'light',
            'primary_seed_color' => '#FFFFFF',
            'secondary_seed_color' => '#999999',
        ];

        AssertEquals($resultData, $check_values);
    }

    public function testUpdate()
    {
        $response = $this->_updateBranding();
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'branding_data' => [
                'theme_data_settings' => [
                    'brightness_default',
                    'primary_seed_color',
                    'secondary_seed_color',
                ],
                'logo_settings' => [
                    'favicon_uri',
                    'light_logo_uri',
                    'dark_logo_uri',
                    'light_icon_uri',
                    'dark_icon_uri',
                ],
            ],
        ]);

        $response = $this->_getBranding();
        $response->assertStatus(200);

        $resultData = [
            'brightness_default' => $response->json()['theme_data_settings']['brightness_default'],
            'primary_seed_color' => $response->json()['theme_data_settings']['primary_seed_color'],
            'secondary_seed_color' => $response->json()['theme_data_settings']['secondary_seed_color'],
        ];

        $check_values = [
            'brightness_default' => 'dark',
            'primary_seed_color' => $this->tenant->theme_primary_seed_color,
            'secondary_seed_color' => $this->tenant->theme_secondary_seed_color,
        ];

        AssertEquals($resultData, $check_values);
    }

    public function testManifest()
    {
        $response = $this->_getManifest();

        $response->assertJsonStructure([
            'name',
            'short_name',
            'description',
            'start_url',
            'display',
            'background_color',
            'theme_color',
            'icons',
        ]);

        assertEquals('#FFFFFF', $response->json()['theme_color']);
        assertEquals('#FFFFFF', $response->json()['background_color']);

        assertcount(3, $response->json()['icons']);
        assertEquals(['src', 'sizes', 'type'], array_keys($response->json()['icons'][0]));
        assertEquals(['src', 'sizes', 'type'], array_keys($response->json()['icons'][1]));
        assertEquals(['src', 'sizes', 'type', 'purpose'], array_keys($response->json()['icons'][2]));

        $response->assertStatus(200);
    }

    //    public function testFavicon() {
    //        $response = $this->_getFavicon();
    //        $response->assertStatus(200);
    //        $response->assertHeader('Content-Type', 'image/vnd.microsoft.icon');
    //    }

    public function testLogoLight()
    {
        $response = $this->_getLogo('light');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testLogoDark()
    {
        $response = $this->_getLogo('dark');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIcon192()
    {
        $response = $this->_getIcon('192x192');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIcon512()
    {
        $response = $this->_getIcon('512x512');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIconMaskable512()
    {
        $response = $this->_getIcon('maskable-512x512');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    protected function _updateBranding(): TestResponse
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}branding/update",
            data: $this->_payloadBrandingUpdate(),
            headers: $this->getHeaders(),
        );

        $this->tenantBrandingMutated = true;

        return $response;
    }

    protected function _getFavicon(): TestResponse
    {
        return $this->get("{$this->base_tenant_url}favicon.ico");
    }

    protected function _getIcon(string $iconType): TestResponse
    {
        return $this->get("{$this->base_tenant_url}icon/icon-$iconType.png");
    }

    protected function _getLogo(string $iconType): TestResponse
    {
        return $this->get("{$this->base_tenant_url}logo-$iconType.png");
    }

    protected function _getManifest(): TestResponse
    {
        return $this->get("{$this->base_tenant_url}manifest.json");
    }

    protected function _getBranding(): TestResponse
    {
        return $this->get("{$this->base_api_tenant}environment");
    }

    protected function _payloadBrandingUpdate(): array
    {

        $landlord_favicon = UploadedFile::fake()->create('favicon.ico', 10, 'image/vnd.microsoft.icon');
        $light_logo_uri = UploadedFile::fake()->image('light-logo.png', 100, 400);
        $dark_logo_uri = UploadedFile::fake()->image('dark-logo.png', 200, 200);

        $this->tenant->theme_secondary_seed_color = fake()->hexColor();
        $this->tenant->theme_primary_seed_color = fake()->hexColor();

        return [
            'theme_data_settings' => [
                'brightness_default' => 'dark',
                'primary_seed_color' => $this->tenant->theme_primary_seed_color,
                'secondary_seed_color' => $this->tenant->theme_secondary_seed_color,
            ],
            'logo_settings' => [
                'light_logo_uri' => $light_logo_uri,
                'dark_logo_uri' => $dark_logo_uri,
                'favicon_uri' => $landlord_favicon,
            ],
            'pwa_icon' => UploadedFile::fake()->image('dark-logo.png', 1024, 1024),
        ];
    }

    private function resetTenantBrandingToDefaults(): void
    {
        $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}branding/update",
            data: $this->defaultBrandingPayload(),
            headers: $this->getHeaders(),
        );
    }

    private function defaultBrandingPayload(): array
    {
        $favicon = UploadedFile::fake()->create('reset-favicon.ico', 10, 'image/vnd.microsoft.icon');

        return [
            'theme_data_settings' => [
                'brightness_default' => 'light',
                'primary_seed_color' => '#FFFFFF',
                'secondary_seed_color' => '#999999',
            ],
            'logo_settings' => [
                'light_logo_uri' => UploadedFile::fake()->image('reset-light-logo.png', 350, 512),
                'dark_logo_uri' => UploadedFile::fake()->image('reset-dark-logo.png', 350, 512),
                'favicon_uri' => $favicon,
            ],
            'pwa_icon' => UploadedFile::fake()->image('reset-pwa-icon.png', 512, 512),
        ];
    }
}
