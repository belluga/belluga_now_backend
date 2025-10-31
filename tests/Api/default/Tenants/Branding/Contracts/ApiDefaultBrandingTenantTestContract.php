<?php

namespace Tests\Api\default\Tenants\Branding\Contracts;

use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseTenant;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;

abstract class ApiDefaultBrandingTenantTestContract extends TestCaseTenant {

    public function testDefaultBranding() {
        $response = $this->_getBranding();
        $response->assertStatus(200);

        $resultData = [
            "dark_scheme_data" => [
                "primary_seed_color" => $response->json()['theme_data_settings']['dark_scheme_data']['primary_seed_color'],
                "secondary_seed_color" => $response->json()['theme_data_settings']['dark_scheme_data']['secondary_seed_color'],
            ],
            "light_scheme_data" => [
                "primary_seed_color" => $response->json()['theme_data_settings']['light_scheme_data']['primary_seed_color'],
                "secondary_seed_color" => $response->json()['theme_data_settings']['light_scheme_data']['secondary_seed_color'],
            ]
        ];

        $check_values = [
            "dark_scheme_data" => [
                "primary_seed_color" => "#CCCCCC",
                "secondary_seed_color" => "#DDDDDD",
            ],
            "light_scheme_data" => [
                "primary_seed_color" => "#FFFFFF",
                "secondary_seed_color" => "#999999",
            ],
        ];

        AssertEquals($resultData, $check_values);
    }

    public function testUpdate() {
        $response = $this->_updateBranding();
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "branding_data" => [
                "theme_data_settings"=> [
                    "dark_scheme_data" => [
                        'primary_seed_color',
                        'secondary_seed_color',
                    ],
                    "light_scheme_data"=> [
                        'primary_seed_color',
                        'secondary_seed_color',
                    ]
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

        $response = $this->_getBranding();
        $response->assertStatus(200);

        $resultData = [
            "dark_scheme_data" => [
                "primary_seed_color" => $response->json()['theme_data_settings']['dark_scheme_data']['primary_seed_color'],
                "secondary_seed_color" => $response->json()['theme_data_settings']['dark_scheme_data']['secondary_seed_color'],
            ],
            "light_scheme_data" => [
                "primary_seed_color" => $response->json()['theme_data_settings']['light_scheme_data']['primary_seed_color'],
                "secondary_seed_color" => $response->json()['theme_data_settings']['light_scheme_data']['secondary_seed_color'],
            ]
        ];

        $check_values = [
            "dark_scheme_data" => [
                "primary_seed_color" => "#CCCCCC",
                "secondary_seed_color" => $this->tenant->dark_scheme_data_secondary,
            ],
            "light_scheme_data" => [
                "primary_seed_color" => $this->tenant->light_scheme_data_primary,
                "secondary_seed_color" => "#999999",
            ],
        ];

        AssertEquals($resultData, $check_values);
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

        assertEquals($this->tenant->light_scheme_data_primary, $response->json()['theme_color']);
        assertEquals($this->tenant->light_scheme_data_primary, $response->json()['background_color']);
        ;
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
            uri: "{$this->base_api_tenant}branding/update",
            data: $this->_payloadBrandingUpdate(),
            headers: $this->getHeaders(),
        );
    }

    protected function _getFavicon(): TestResponse {
        return $this->get("{$this->base_tenant_url}favicon.ico");
    }

    protected function _getIcon(String $iconType): TestResponse {
        return $this->get("{$this->base_tenant_url}icon/icon-$iconType.png");
    }

    protected function _getLogo(String $iconType): TestResponse {
        return $this->get("{$this->base_tenant_url}logo-$iconType.png");
    }

    protected function _getManifest(): TestResponse {
        return $this->get("{$this->base_tenant_url}manifest.json");
    }

    protected function _getBranding(): TestResponse {
        return $this->get("{$this->base_tenant_url}environment");
    }

    protected function _payloadBrandingUpdate(): array {

        $landlord_favicon =  UploadedFile::fake()->create('favicon.ico', 10, 'image/vnd.microsoft.icon');
        $light_logo_uri = UploadedFile::fake()->image('light-logo.png', 100, 400);
        $dark_logo_uri = UploadedFile::fake()->image('dark-logo.png', 200, 200);

        $this->tenant->dark_scheme_data_secondary = fake()->hexColor();
        $this->tenant->light_scheme_data_primary = fake()->hexColor();

        return [
            "theme_data_settings" => [
                "dark_scheme_data" => [
                    'secondary_seed_color' => $this->tenant->dark_scheme_data_secondary,
                ],
                "light_scheme_data" => [
                    'primary_seed_color' => $this->tenant->light_scheme_data_primary,
                ],
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
