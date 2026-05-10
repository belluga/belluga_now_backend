<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Application\Media\CanonicalImageMediaService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandingManifestService
{
    public function __construct(
        private readonly CanonicalImageMediaService $canonicalImageMediaService,
        private readonly BrandingAssetDefinitionFactory $brandingAssetDefinitionFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildManifest(string $host): array
    {
        $tenant = Tenant::current();

        $manifest = $tenant !== null
            ? $this->buildTenantManifest($tenant)
            : $this->buildLandlordManifest(Landlord::singleton());

        $manifest['icons'] = [
            [
                'src' => "https://{$host}/icon/icon-192x192.png",
                'sizes' => '192x192',
                'type' => 'image/png',
            ],
            [
                'src' => "https://{$host}/icon/icon-512x512.png",
                'sizes' => '512x512',
                'type' => 'image/png',
            ],
            [
                'src' => "https://{$host}/icon/icon-maskable-512x512.png",
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
        ];

        return $manifest;
    }

    public function resolveLogoSetting(string $parameter): ?string
    {
        $landlordBranding = Landlord::singleton()->branding_data;
        $tenantBranding = Tenant::current()?->branding_data ?? [];

        $tenantValue = $tenantBranding['logo_settings'][$parameter] ?? null;

        return $tenantValue ?: ($landlordBranding['logo_settings'][$parameter] ?? null);
    }

    public function resolvePwaIcon(string $parameter): ?string
    {
        $landlordBranding = Landlord::singleton()->branding_data;
        $tenantBranding = Tenant::current()?->branding_data ?? [];

        $tenantValue = $tenantBranding['pwa_icon'][$parameter] ?? null;

        return $tenantValue ?: ($landlordBranding['pwa_icon'][$parameter] ?? null);
    }

    public function resolveFaviconAsset(): ?string
    {
        $tenantBranding = Tenant::current()?->branding_data ?? [];
        $landlordBranding = Landlord::singleton()->branding_data ?? [];

        return $this->resolveFaviconAssetFromBranding($tenantBranding)
            ?? $this->resolveFaviconAssetFromBranding($landlordBranding);
    }

    public function resolveStoragePath(?string $uri): ?string
    {
        if (! $uri) {
            return null;
        }

        $tenant = Tenant::current();
        $brandables = $tenant instanceof Tenant
            ? [$tenant, Landlord::singleton()]
            : [Landlord::singleton()];

        foreach ($brandables as $brandable) {
            foreach ($this->brandingAssetDefinitionFactory->definitions($brandable) as $definition) {
                $resolved = $this->canonicalImageMediaService->resolveStoragePath($definition, $uri);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $urlPath = parse_url($uri, PHP_URL_PATH);
        if (! is_string($urlPath) || $urlPath === '') {
            return null;
        }

        $storagePath = Str::after($urlPath, '/storage/');

        return $storagePath === $urlPath ? null : $storagePath;
    }

    public function assetResponse(?string $path)
    {
        $localPath = $this->resolveStoragePath($path);

        if (! $this->hasUsableAssetPath($localPath)) {
            return response('', 404);
        }

        return response()->file(Storage::disk('public')->path($localPath));
    }

    public function hasUsableAssetUri(?string $uri): bool
    {
        return $this->hasUsableAssetPath($this->resolveStoragePath($uri));
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array{has_dedicated_asset: bool, uses_pwa_fallback: bool}
     */
    public function resolveFaviconRouteStateFromBranding(array $branding): array
    {
        $faviconUri = $branding['logo_settings']['favicon_uri'] ?? null;
        $hasDedicatedAsset = is_string($faviconUri)
            && trim($faviconUri) !== ''
            && $this->hasUsableAssetUri(trim($faviconUri));

        return [
            'has_dedicated_asset' => $hasDedicatedAsset,
            'uses_pwa_fallback' => ! $hasDedicatedAsset
                && $this->resolveFirstUsablePwaFaviconCandidate($branding) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveFaviconAssetFromBranding(array $branding): ?string
    {
        $candidates = [
            $branding['logo_settings']['favicon_uri'] ?? null,
            $this->resolveFirstUsablePwaFaviconCandidate($branding),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '' && $this->hasUsableAssetUri($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveFirstUsablePwaFaviconCandidate(array $branding): ?string
    {
        foreach (['icon192_uri', 'icon512_uri', 'source_uri'] as $key) {
            $candidate = $branding['pwa_icon'][$key] ?? null;
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '' && $this->hasUsableAssetUri($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTenantManifest(Tenant $tenant): array
    {
        return $tenant->getManifestData();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLandlordManifest(Landlord $landlord): array
    {
        return $landlord->getManifestData();
    }

    private function hasUsableAssetPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return false;
        }

        return $disk->size($path) > 0;
    }
}
