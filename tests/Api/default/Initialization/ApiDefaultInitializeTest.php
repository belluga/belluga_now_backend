<?php

namespace Tests\Api\default\Initialization;

use Illuminate\Http\UploadedFile;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

use Tests\Traits\RefreshLandlordAndTenantDatabases;

class ApiDefaultInitializeTest extends TestCase {
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    // protected function tearDown(): void
    // {
    //     $this->refreshLandlordAndTenantDatabases();
    //     parent::tearDown();
    // }

    public function testInitiate(): void {

        $response = $this->initiateCheck();
        $response->assertStatus(403);

        $this->landlord->user_superadmin->name = fake()->name();
        $this->landlord->user_superadmin->email_1 = fake()->email();
        $this->landlord->user_superadmin->email_2 = fake()->email();
        $this->landlord->user_superadmin->password = fake()->password(8);

        $this->landlord->role_superadmin->name = "Super Admin";

        $response = $this->initiate();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "data" => [
                "user" => [
                    "name",
                    "emails",
                    "token"
                ],
                "tenant" => [
                    "name",
                    "subdomain",
                    "slug"
                ],
                "role" => [
                    "name",
                    "permissions"
                ],
                "landlord" => [
                    "name",
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
                ]
            ],
        ]);

        $this->landlord->user_superadmin->user_id = $response->json()['data']['user']["id"];
        $this->landlord->user_superadmin->token = $response->json()['data']['user']['token'];

        $this->landlord->tenant_primary->slug = $response->json()['data']['tenant']["slug"];
        $this->landlord->tenant_primary->id = $response->json()['data']['tenant']["id"];

        $this->landlord->tenant_primary->role_admin->name = "Admin";
        $this->landlord->tenant_primary->role_admin->id = $response->json()['data']['tenant']['role_admin_id'];

        $this->landlord->role_superadmin->id = $response->json()['data']["role"]["id"];
    }

    // public function testInitiateAgain(): void {
    //     $response = $this->initiate();
    //     $response->assertStatus(403);


    //     $response = $this->initiateCheck();
    //     $response->assertStatus(200);

    //     $response->assertJsonStructure([
    //         "message",
    //         "errors"
    //     ]);
    // }

    protected function initiate(): TestResponse {
        return $this->post(
            'api/v1/initialize',
            $this->payloadInitiate(),
            [
                'Content-Type' => 'multipart/form-data'
            ],

        );
    }

    protected function initiateCheck(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "api/v1/initialize",
        );
    }

    protected function payloadInitiate(): array {

        $favicon_fixture_path = base_path('tests/Assets/landlord.ico');
        $favicon_for_upload = new UploadedFile(
            path: $favicon_fixture_path,
            originalName: 'favicon.ico',
            mimeType: 'image/vnd.microsoft.icon',
            error: null,
            test: true
        );

        $light_icon_uri = UploadedFile::fake()->image('light-icon.png', 50, 512);
        $dark_icon_uri = UploadedFile::fake()->image('dark-icon.png', 300, 192);
        $light_logo_uri = UploadedFile::fake()->image('light-logo.png', 350, 512);
        $dark_logo_uri = UploadedFile::fake()->image('dark-logo.png', 400, 512);

        return [
            "landlord" => [
                "name" => fake()->company()
            ],
            "user" => [
                "name" => $this->landlord->user_superadmin->name,
                "email" => $this->landlord->user_superadmin->email_1,
                "password" => $this->landlord->user_superadmin->password
            ],
            "tenant" => [
                "name" => $this->landlord->tenant_primary->name,
                "subdomain" => $this->landlord->tenant_primary->subdomain,
            ],
            "role" => [
                "name" =>  $this->landlord->role_superadmin->name,
                "permissions" => [
                    "*"
                ],
            ],
            "branding_data" => [
                "theme_data_settings" => [
                    "dark_scheme_data" => [
                        'primary_seed_color' => '#000000',
                        'secondary_seed_color' => '#DDDDDD',
                    ],
                    "light_scheme_data" => [
                        'primary_seed_color' => '#FFFFFF',
                        'secondary_seed_color' => '#999999',
                    ]
                ],
                'logo_settings' => [
                    'light_icon_uri' => $light_icon_uri,
                    'dark_icon_uri' => $dark_icon_uri,
                    'light_logo_uri' => $light_logo_uri,
                    'dark_logo_uri' => $dark_logo_uri,
                    'favicon_uri' => $favicon_for_upload,
                ],
                'pwa_icon' => UploadedFile::fake()->image('dark-logo.png', 1024, 1024),
            ]
        ];
    }
}
