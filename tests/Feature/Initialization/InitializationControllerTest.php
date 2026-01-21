<?php

declare(strict_types=1);

namespace Tests\Feature\Initialization;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class InitializationControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        parent::tearDown();
    }

    public function testSystemInitializesSuccessfully(): void
    {
        $tenantHost = "{$this->payload()['tenant']['subdomain']}.{$this->host}";
        $initializeUrl = "http://{$tenantHost}/api/v1/initialize";
        $response = $this->postJson($initializeUrl, $this->payload());

        $response->assertStatus(201);
        $response->assertJsonPath('data.user.name', 'Admin Test');

        $this->assertDatabaseCount('landlords', 1, 'landlord');
        $this->assertDatabaseCount('tenants', 1, 'landlord');
    }

    public function testSubsequentInitializationIsRejected(): void
    {
        $tenantHost = "{$this->payload()['tenant']['subdomain']}.{$this->host}";
        $initializeUrl = "http://{$tenantHost}/api/v1/initialize";
        $this->postJson($initializeUrl, $this->payload())->assertCreated();

        $response = $this->postJson($initializeUrl, $this->payload());
        $response->assertStatus(403);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'landlord' => [
                'name' => 'Belluga HQ',
            ],
            'user' => [
                'name' => 'Admin Test',
                'email' => 'admin@example.org',
                'password' => 'secret123',
            ],
            'tenant' => [
                'name' => 'Belluga Solutions Test',
                'subdomain' => 'belluga-test',
                'domains' => [
                    'tenant.belluga.test',
                ],
            ],
            'role' => [
                'name' => 'Super Admin',
                'permissions' => ['*'],
            ],
            'branding_data' => [
                'theme_data_settings' => [
                    'brightness_default' => 'light',
                    'primary_seed_color' => '#FFFFFF',
                    'secondary_seed_color' => '#111111',
                ],
                'logo_settings' => [
                    'light_logo_uri' => UploadedFile::fake()->image('light-logo.png'),
                    'dark_logo_uri' => UploadedFile::fake()->image('dark-logo.png'),
                    'light_icon_uri' => UploadedFile::fake()->image('light-icon.png'),
                    'dark_icon_uri' => UploadedFile::fake()->image('dark-icon.png'),
                    'favicon_uri' => UploadedFile::fake()->create('favicon.ico', 10, 'image/vnd.microsoft.icon'),
                ],
                'pwa_icon' => UploadedFile::fake()->image('pwa-icon.png', 1024, 1024),
            ],
        ];
    }
}
