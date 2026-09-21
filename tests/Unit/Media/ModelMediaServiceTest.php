<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use Belluga\Media\Application\ModelMediaService;
use Belluga\Media\Contracts\TenantMediaScopeResolverContract;
use Belluga\Media\Support\MediaModelDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelMediaServiceTest extends TestCase
{
    public function test_store_upload_can_be_used_outside_apply_uploads_for_nested_payloads(): void
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
            legacyPublicPathPrefix: '/branding-public-web',
            canonicalPublicPathPrefix: '/api/v1/media/branding-public-web',
            storageDirectory: 'branding_public_web',
            slots: ['default_image'],
        );

        $model = new FakeMediaModel('brand-123');
        $storedUrl = $service->storeUpload(
            baseUrl: 'https://tenant-zeta.test',
            model: $model,
            kind: 'default_image',
            file: UploadedFile::fake()->image('default-image.jpg', 1200, 630),
            definition: $definition,
        );

        $this->assertMatchesRegularExpression(
            '#^/api/v1/media/branding-public-web/brand-123/default_image\?v=[A-Za-z0-9]+$#',
            $storedUrl,
        );
        Storage::disk('public')->assertExists(
            'tenants/tenant-zeta/branding_public_web/brand-123/default_image.jpg'
        );
    }

    public function test_remove_upload_deletes_existing_slot_file(): void
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
            legacyPublicPathPrefix: '/branding-public-web',
            canonicalPublicPathPrefix: '/api/v1/media/branding-public-web',
            storageDirectory: 'branding_public_web',
            slots: ['default_image'],
        );

        $model = new FakeMediaModel('brand-456');
        Storage::disk('public')->put(
            'tenants/tenant-zeta/branding_public_web/brand-456/default_image.png',
            'default-image'
        );

        $service->removeUpload(
            model: $model,
            kind: 'default_image',
            definition: $definition,
            baseUrl: 'https://tenant-zeta.test',
        );

        Storage::disk('public')->assertMissing(
            'tenants/tenant-zeta/branding_public_web/brand-456/default_image.png'
        );
    }

    public function test_resolve_variant_media_path_for_base_url_supports_gallery_variants(): void
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
            legacyPublicPathPrefix: '/account-profiles',
            canonicalPublicPathPrefix: '/api/v1/media/account-profiles',
            storageDirectory: 'account_profiles',
            slots: ['avatar', 'cover'],
        );

        Storage::disk('public')->put(
            'tenants/tenant-zeta/account_profiles/profile-123/gallery-item-main.jpg',
            'master'
        );
        Storage::disk('public')->put(
            'tenants/tenant-zeta/account_profiles/profile-123/gallery-item-main.thumb.jpg',
            'thumb'
        );

        $model = new FakeMediaModel('profile-123');

        $masterPath = $service->resolveVariantMediaPathForBaseUrl(
            $model,
            'gallery-item-main',
            null,
            $definition,
            'https://tenant-zeta.test',
        );
        $thumbPath = $service->resolveVariantMediaPathForBaseUrl(
            $model,
            'gallery-item-main',
            'thumb',
            $definition,
            'https://tenant-zeta.test',
        );

        $this->assertSame(
            'tenants/tenant-zeta/account_profiles/profile-123/gallery-item-main.jpg',
            $masterPath
        );
        $this->assertSame(
            'tenants/tenant-zeta/account_profiles/profile-123/gallery-item-main.thumb.jpg',
            $thumbPath
        );
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
