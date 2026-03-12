<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Media;

use App\Application\Media\MapFilterImageStorageService;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

final class MapFilterImageUploadTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function test_requires_auth(): void
    {
        $response = $this->post("{$this->base_tenant_api_admin}media/map-filter-image", [
            'key' => 'events',
            'image' => UploadedFile::fake()->image('events.png', 1024, 1024),
        ]);

        $response->assertStatus(401);
    }

    public function test_cross_tenant_without_access_is_forbidden(): void
    {
        $response = $this->withHeaders($this->uploadHeadersFor($this->landlord->user_cross_tenant_visitor))
            ->post("{$this->base_tenant_api_admin}media/map-filter-image", [
                'key' => 'events',
                'image' => UploadedFile::fake()->image('events.png', 1024, 1024),
            ]);

        $response->assertStatus(403);
    }

    public function test_rejects_missing_map_poi_settings_ability(): void
    {
        $user = LandlordUser::query()->findOrFail($this->landlord->user_superadmin->user_id);
        $token = $user->createToken('map-filter-image-limited', ['account-users:view'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post("{$this->base_tenant_api_admin}media/map-filter-image", [
            'key' => 'events',
            'image' => UploadedFile::fake()->image('events.png', 1024, 1024),
        ]);

        $response->assertStatus(403);
    }

    public function test_rejects_non_square_image(): void
    {
        $response = $this->withHeaders($this->uploadHeadersFor($this->landlord->user_superadmin))
            ->post("{$this->base_tenant_api_admin}media/map-filter-image", [
                'key' => 'events',
                'image' => UploadedFile::fake()->image('events.png', 1024, 900),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }

    public function test_uploads_map_filter_image_and_returns_public_uri(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->uploadHeadersFor($this->landlord->user_superadmin))
            ->post("{$this->base_tenant_api_admin}media/map-filter-image", [
                'key' => 'Events_Main',
                'image' => UploadedFile::fake()->image('events.png', 1024, 1024),
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.key', 'events_main');

        $imageUri = (string) $response->json('data.image_uri');
        $this->assertNotSame('', $imageUri);
        $this->assertStringContainsString('/api/v1/media/map-filters/events_main', $imageUri);
        $this->assertSame('/api/v1/media/map-filters/events_main', parse_url($imageUri, PHP_URL_PATH));
        $resolvedPath = app(MapFilterImageStorageService::class)->resolveMediaPathForBaseUrl(
            'events_main',
            $this->base_tenant_url,
        );
        $this->assertNotNull(
            $resolvedPath,
            'Stored file was not resolvable after upload.'
        );
        $absolutePath = Storage::disk('public')->path($resolvedPath);
        $this->assertNotFalse(@getimagesize($absolutePath));

        $publicResponse = $this->get($imageUri);
        $publicResponse->assertOk();
        $publicResponse->assertHeader('ETag');

        Storage::disk('public')->assertExists(
            'tenants/'.$this->tenant->slug.'/map_filters/events_main.png'
        );
    }

    public function test_replacing_map_filter_image_returns_a_new_public_fingerprint(): void
    {
        Storage::fake('public');

        $firstResponse = $this->withHeaders($this->uploadHeadersFor($this->landlord->user_superadmin))
            ->post("{$this->base_tenant_api_admin}media/map-filter-image", [
                'key' => 'events_main',
                'image' => UploadedFile::fake()->image('events.png', 1024, 1024),
            ]);

        $firstResponse->assertOk();
        $firstUri = (string) $firstResponse->json('data.image_uri');

        $secondResponse = $this->withHeaders($this->uploadHeadersFor($this->landlord->user_superadmin))
            ->post("{$this->base_tenant_api_admin}media/map-filter-image", [
                'key' => 'events_main',
                'image' => UploadedFile::fake()->image('events.jpg', 1024, 1024),
            ]);

        $secondResponse->assertOk();
        $secondUri = (string) $secondResponse->json('data.image_uri');

        $this->assertNotSame('', $firstUri);
        $this->assertNotSame('', $secondUri);
        $this->assertNotSame($firstUri, $secondUri);
        $this->assertStringContainsString('/api/v1/media/map-filters/events_main', $secondUri);
    }

    private function uploadHeadersFor(UserLabels $user): array
    {
        return [
            'Authorization' => "Bearer {$user->token}",
            'Accept' => 'application/json',
        ];
    }
}
