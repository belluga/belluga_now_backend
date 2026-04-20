<?php

namespace Tests\Api\v1\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;

class ApiV1BrandingAdminTest extends TestCaseAuthenticated
{
    private bool $brandingMutated = false;

    protected function tearDown(): void
    {
        if ($this->brandingMutated) {
            $this->resetBrandingToDefaults();
            $this->brandingMutated = false;
        }

        parent::tearDown();
    }

    public function test_branding()
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

    public function test_update()
    {
        $respoonse = $this->_updateBranding();
        $respoonse->assertStatus(200);

        $respoonse->assertJsonStructure([
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

        $colorUpdate = $respoonse->json()['branding_data']['theme_data_settings']['primary_seed_color'];
        AssertEquals($colorUpdate, '#CCCCCC');
        $this->assertStringContainsString(
            '/logo-light.png',
            (string) $respoonse->json()['branding_data']['logo_settings']['light_logo_uri']
        );
        $this->assertStringContainsString(
            '/icon/icon-source.png',
            (string) $respoonse->json()['branding_data']['pwa_icon']['source_uri']
        );
    }

    public function test_manifest()
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

    public function test_logo_light()
    {
        $response = $this->_getLogo('light');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_logo_dark()
    {
        $response = $this->_getLogo('dark');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_icon192()
    {
        $response = $this->_getIcon('192x192');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_icon512()
    {
        $response = $this->_getIcon('512x512');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_icon_source()
    {
        $response = $this->_getIcon('source');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_icon_maskable512()
    {
        $response = $this->_getIcon('maskable-512x512');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    protected function _updateBranding(): TestResponse
    {
        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/branding/update',
            data: $this->_payloadBrandingUpdate(),
            headers: $this->getHeaders(),
        );

        $this->brandingMutated = true;

        return $response;
    }

    protected function _getFavicon(): TestResponse
    {
        return $this->get('favicon.ico');
    }

    protected function _getBranding(): TestResponse
    {
        $tenantBase = "http://{$this->landlord->tenant_primary->subdomain}.{$this->host}/";

        return $this->json(
            method: 'get',
            uri: "{$tenantBase}api/v1/environment",
        );
    }

    protected function _getIcon(string $iconType): TestResponse
    {
        return $this->get("icon/icon-$iconType.png");
    }

    protected function _getLogo(string $iconType): TestResponse
    {
        return $this->get("logo-$iconType.png");
    }

    protected function _getManifest(): TestResponse
    {
        return $this->get('manifest.json');
    }

    protected function _payloadBrandingUpdate(): array
    {

        $landlord_favicon = UploadedFile::fake()->create('favicon.ico', 30, 'image/vnd.microsoft.icon');
        $light_logo_uri = UploadedFile::fake()->image('light-logo.png', 100, 512);
        $dark_logo_uri = UploadedFile::fake()->image('dark-logo.png', 200, 513);

        return [
            'theme_data_settings' => [
                'brightness_default' => 'dark',
                'primary_seed_color' => '#CCCCCC',
                'secondary_seed_color' => '#000000',
            ],
            'logo_settings' => [
                'light_logo_uri' => $light_logo_uri,
                'dark_logo_uri' => $dark_logo_uri,
                'favicon_uri' => $landlord_favicon,
                'pwa_icon' => UploadedFile::fake()->image('dark-logo.png', 1024, 1024),
            ],
        ];
    }

    private function resetBrandingToDefaults(): void
    {
        $this->json(
            method: 'post',
            uri: 'admin/api/v1/branding/update',
            data: $this->defaultBrandingPayload(),
            headers: $this->getHeaders(),
        );
    }

    private function defaultBrandingPayload(): array
    {
        return [
            'theme_data_settings' => [
                'brightness_default' => 'light',
                'primary_seed_color' => '#FFFFFF',
                'secondary_seed_color' => '#999999',
            ],
            'logo_settings' => [
                'light_logo_uri' => UploadedFile::fake()->image('default-light-logo.png', 350, 512),
                'dark_logo_uri' => UploadedFile::fake()->image('default-dark-logo.png', 400, 512),
                'light_icon_uri' => UploadedFile::fake()->image('default-light-icon.png', 128, 128),
                'dark_icon_uri' => UploadedFile::fake()->image('default-dark-icon.png', 128, 128),
                'favicon_uri' => UploadedFile::fake()->create('default-favicon.ico', 24, 'image/vnd.microsoft.icon'),
                'pwa_icon' => UploadedFile::fake()->image('default-pwa-icon.png', 1024, 1024),
            ],
        ];
    }
}
