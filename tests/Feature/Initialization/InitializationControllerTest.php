<?php

declare(strict_types=1);

namespace Tests\Feature\Initialization;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
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

    public function test_system_initializes_successfully(): void
    {
        $response = $this->withServerVariables([
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => $this->host,
        ])->post('/api/v1/initialize', $this->payload(), [
            'Content-Type' => 'multipart/form-data',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user.name', 'Admin Test');
        $this->assertStringContainsString(
            '/logo-light.png',
            (string) $response->json('data.landlord.branding_data.logo_settings.light_logo_uri')
        );
        $this->assertStringContainsString(
            '/favicon.ico',
            (string) $response->json('data.landlord.branding_data.logo_settings.favicon_uri')
        );
        $this->assertStringContainsString(
            '/icon/icon-source.png',
            (string) $response->json('data.landlord.branding_data.pwa_icon.source_uri')
        );
        $this->assertStringContainsString(
            '/icon/icon-192x192.png',
            (string) $response->json('data.landlord.branding_data.pwa_icon.icon192_uri')
        );

        $this->assertSame(1, Landlord::query()->count());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_subsequent_initialization_is_rejected(): void
    {
        $this->withServerVariables([
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => $this->host,
        ])->post('/api/v1/initialize', $this->payload(), [
            'Content-Type' => 'multipart/form-data',
        ])->assertCreated();

        $response = $this->withServerVariables([
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => $this->host,
        ])->post('/api/v1/initialize', $this->payload(), [
            'Content-Type' => 'multipart/form-data',
        ]);
        $response->assertStatus(403);
    }

    public function test_initialization_route_is_not_available_on_tenant_domain(): void
    {
        $tenantHost = "{$this->payload()['tenant']['subdomain']}.{$this->host}";

        $response = $this->withServerVariables([
            'HTTP_HOST' => $tenantHost,
            'SERVER_NAME' => $tenantHost,
        ])->post('/api/v1/initialize', $this->payload(), [
            'Content-Type' => 'multipart/form-data',
        ]);
        $response->assertStatus(404);
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
