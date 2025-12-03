<?php

namespace Api\default\Admin;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;

class ApiDefaultBrandingAdminTest extends TestCaseAuthenticated {

    public function testBranding() {
        $response = $this->_getBranding();
        $response->assertStatus(200);

        $resultData = [
            "brightness_default" => $response->json()['theme_data_settings']['brightness_default'],
            "primary_seed_color" => $response->json()['theme_data_settings']['primary_seed_color'],
            "secondary_seed_color" => $response->json()['theme_data_settings']['secondary_seed_color'],
        ];

        $check_values = [
            "brightness_default" => "light",
            "primary_seed_color" => "#FFFFFF",
            "secondary_seed_color" => "#999999",
        ];

        AssertEquals($resultData, $check_values);
    }

    public function testUpdate() {
        $respoonse = $this->_updateBranding();
        $respoonse->assertStatus(200);

        $respoonse->assertJsonStructure([
            "branding_data" => [
                "theme_data_settings"=> [
                    'brightness_default',
                    'primary_seed_color',
                    'secondary_seed_color',
                ],
                "logo_settings" => [
                    "favicon_uri",
                    "light_logo_uri",
                    "dark_logo_uri",
                    "light_icon_uri",
                    "dark_icon_uri"
                ]
            ]
        ]);

        $colorUpdate = $respoonse->json()['branding_data']['theme_data_settings']['primary_seed_color'];
        AssertEquals($colorUpdate, '#CCCCCC');
    }

    public function testManifest() {
        $response = $this->_getManifest();

        $response->assertJsonStructure([
            "name",
            "short_name",
            "description",
            "start_url",
            "display",
            "background_color",
            "theme_color",
            "icons"
        ]);

        assertcount(3, $response->json()['icons']);
        assertEquals(['src', 'sizes', 'type'], array_keys($response->json()['icons'][0]));
        assertEquals(['src', 'sizes', 'type'], array_keys($response->json()['icons'][1]));
        assertEquals(['src', 'sizes', 'type', 'purpose'], array_keys($response->json()['icons'][2]));;

        $response->assertStatus(200);
    }

//    public function testFavicon() {
//        $response = $this->_getFavicon();
//        $response->assertStatus(200);
//        $response->assertHeader('Content-Type', 'image/vnd.microsoft.icon');
//    }

    public function testLogoLight() {
        $response = $this->_getLogo("light");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testLogoDark() {
        $response = $this->_getLogo("dark");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIcon192() {
        $response = $this->_getIcon("192x192");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIcon512() {
        $response = $this->_getIcon("512x512");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function testIconMaskable512() {
        $response = $this->_getIcon("maskable-512x512");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
    }

    protected function _updateBranding(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/branding/update",
            data: $this->_payloadBrandingUpdate(),
            headers: $this->getHeaders(),
        );
    }

    protected function _getFavicon(): TestResponse {
        return $this->get("favicon.ico");
    }

    protected function _getBranding(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "environment",
            headers: $this->getHeaders(),
        );
    }

    protected function _getIcon(String $iconType): TestResponse {
        return $this->get("icon/icon-$iconType.png");
    }

    protected function _getLogo(String $iconType): TestResponse {
        return $this->get("logo-$iconType.png");
    }

    protected function _getManifest(): TestResponse {
        return $this->get("manifest.json");
    }

    protected function _payloadBrandingUpdate(): array {

        $landlord_favicon =  UploadedFile::fake()->create('favicon.ico', 30, 'image/vnd.microsoft.icon');
        $light_logo_uri = UploadedFile::fake()->image('light-logo.png', 100, 512);
        $dark_logo_uri = UploadedFile::fake()->image('dark-logo.png', 200, 513);

        return [
            "theme_data_settings" => [
                'brightness_default' => 'dark',
                'primary_seed_color' => '#CCCCCC',
                'secondary_seed_color' => '#000000',
            ],
            'logo_settings' => [
                'light_logo_uri' => $light_logo_uri,
                'dark_logo_uri' => $dark_logo_uri,
                'favicon_uri' => $landlord_favicon,
            ],
            'pwa_icon' => UploadedFile::fake()->image('dark-logo.png', 1024, 1024),
        ];
    }
}
