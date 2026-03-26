<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelMediaServiceTest extends TestCase
{
    public function test_apply_uploads_stores_avatar_and_cover_using_tenant_scoped_directory(): void
    {
        Storage::fake('public');

        $service = new ModelMediaService(new class implements TenantMediaScopeResolverContract
        {
            public function resolveTenantScope(?string $baseUrl): ?string
            {
                return 'tenant-zeta';
            }
        });

        $definition = new MediaModelDefinition(
            legacyPublicPathPrefix: '/static-assets',
            canonicalPublicPathPrefix: '/api/v1/media/static-assets',
            storageDirectory: 'static_assets',
            slots: ['avatar', 'cover'],
        );

        $model = new FakeMediaModel('asset-123');
        $request = Request::create('https://tenant-zeta.test/admin/api/v1/static_assets', 'POST', [], [], [
            'avatar' => UploadedFile::fake()->image('avatar.png', 128, 128),
            'cover' => UploadedFile::fake()->image('cover.jpg', 320, 160),
        ]);

        $updates = $service->applyUploads($request, $model, $definition);

        $this->assertArrayHasKey('avatar_url', $updates);
        $this->assertArrayHasKey('cover_url', $updates);
        $this->assertStringContainsString('/api/v1/media/static-assets/asset-123/avatar', (string) $updates['avatar_url']);
        $this->assertStringContainsString('/api/v1/media/static-assets/asset-123/cover', (string) $updates['cover_url']);
        Storage::disk('public')->assertExists('tenants/tenant-zeta/static_assets/asset-123/avatar.png');
        Storage::disk('public')->assertExists('tenants/tenant-zeta/static_assets/asset-123/cover.jpg');
    }

    public function test_apply_uploads_remove_flags_clear_urls_and_delete_existing_files(): void
    {
        Storage::fake('public');

        $service = new ModelMediaService(new class implements TenantMediaScopeResolverContract
        {
            public function resolveTenantScope(?string $baseUrl): ?string
            {
                return 'tenant-zeta';
            }
        });

        $definition = new MediaModelDefinition(
            legacyPublicPathPrefix: '/static-assets',
            canonicalPublicPathPrefix: '/api/v1/media/static-assets',
            storageDirectory: 'static_assets',
            slots: ['avatar', 'cover'],
        );

        $model = new FakeMediaModel('asset-456');
        Storage::disk('public')->put('tenants/tenant-zeta/static_assets/asset-456/avatar.png', 'avatar');
        Storage::disk('public')->put('tenants/tenant-zeta/static_assets/asset-456/cover.png', 'cover');
        $model->avatar_url = 'https://tenant-zeta.test/api/v1/media/static-assets/asset-456/avatar?v=1';
        $model->cover_url = 'https://tenant-zeta.test/api/v1/media/static-assets/asset-456/cover?v=1';

        $request = Request::create(
            'https://tenant-zeta.test/admin/api/v1/static_assets/asset-456',
            'POST',
            ['remove_avatar' => '1', 'remove_cover' => '1'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
        );
        $this->assertFalse($request->hasFile('avatar'));
        $this->assertFalse($request->hasFile('cover'));
        $this->assertTrue($request->boolean('remove_avatar'));
        $this->assertTrue($request->boolean('remove_cover'));

        $updates = $service->applyUploads($request, $model, $definition);

        $this->assertArrayHasKey('avatar_url', $updates, json_encode($updates));
        $this->assertArrayHasKey('cover_url', $updates, json_encode($updates));
        $this->assertNull($updates['avatar_url'], json_encode($updates));
        $this->assertNull($updates['cover_url'], json_encode($updates));
        Storage::disk('public')->assertMissing('tenants/tenant-zeta/static_assets/asset-456/avatar.png');
        Storage::disk('public')->assertMissing('tenants/tenant-zeta/static_assets/asset-456/cover.png');
    }

    public function test_normalize_public_url_converts_legacy_and_canonical_paths(): void
    {
        $service = new ModelMediaService(new class implements TenantMediaScopeResolverContract
        {
            public function resolveTenantScope(?string $baseUrl): ?string
            {
                return 'tenant-zeta';
            }
        });

        $definition = new MediaModelDefinition(
            legacyPublicPathPrefix: '/static-assets',
            canonicalPublicPathPrefix: '/api/v1/media/static-assets',
            storageDirectory: 'static_assets',
            slots: ['avatar', 'cover'],
        );

        $model = new FakeMediaModel('asset-789');
        $model->updated_at = \DateTimeImmutable::createFromFormat('U', '1700000000') ?: null;

        $legacy = $service->normalizePublicUrl(
            baseUrl: 'https://tenant-zeta.test',
            model: $model,
            kind: 'avatar',
            definition: $definition,
            rawUrl: 'https://tenant-zeta.test/static-assets/asset-789/avatar?v=42',
        );
        $canonical = $service->normalizePublicUrl(
            baseUrl: 'https://tenant-zeta.test',
            model: $model,
            kind: 'cover',
            definition: $definition,
            rawUrl: 'https://tenant-zeta.test/api/v1/media/static-assets/asset-789/cover',
        );

        $this->assertSame(
            'https://tenant-zeta.test/api/v1/media/static-assets/asset-789/avatar?v=42',
            $legacy
        );
        $this->assertSame(
            'https://tenant-zeta.test/api/v1/media/static-assets/asset-789/cover?v=1700000000',
            $canonical
        );
    }

    public function test_normalize_public_url_keeps_external_url_untouched(): void
    {
        $service = new ModelMediaService(new class implements TenantMediaScopeResolverContract
        {
            public function resolveTenantScope(?string $baseUrl): ?string
            {
                return 'tenant-zeta';
            }
        });

        $definition = new MediaModelDefinition(
            legacyPublicPathPrefix: '/static-assets',
            canonicalPublicPathPrefix: '/api/v1/media/static-assets',
            storageDirectory: 'static_assets',
            slots: ['avatar', 'cover'],
        );

        $model = new FakeMediaModel('asset-900');
        $external = 'https://cdn.example.com/media/avatar.png';

        $normalized = $service->normalizePublicUrl(
            baseUrl: 'https://tenant-zeta.test',
            model: $model,
            kind: 'avatar',
            definition: $definition,
            rawUrl: $external,
        );

        $this->assertSame($external, $normalized);
    }
}

final class FakeMediaModel
{
    public ?\DateTimeInterface $updated_at = null;

    public ?string $avatar_url = null;

    public ?string $cover_url = null;

    public bool $saved = false;

    public function __construct(
        public string $_id,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function save(): void
    {
        $this->saved = true;
    }

    public function refresh(): void {}
}
