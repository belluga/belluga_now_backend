<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Application\Media\CanonicalImageMediaService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Application\Tenants\TenantDomainResolverService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandingManifestService
{
    public function __construct(
        private readonly CanonicalImageMediaService $canonicalImageMediaService,
        private readonly BrandingAssetDefinitionFactory $brandingAssetDefinitionFactory,
        private readonly TenantDomainResolverService $tenantDomainResolverService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildManifest(string $host): array
    {
        $tenant = $this->resolveTenantForHost($host);

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

    public function resolveLogoSetting(string $parameter, ?string $host = null): ?string
    {
        $landlordBranding = Landlord::singleton()->branding_data;
        $tenantBranding = $this->resolveTenantForHost($host)?->branding_data ?? [];

        $tenantValue = $tenantBranding['logo_settings'][$parameter] ?? null;

        return $tenantValue ?: ($landlordBranding['logo_settings'][$parameter] ?? null);
    }

    public function resolvePwaIcon(string $parameter, ?string $host = null): ?string
    {
        $landlordBranding = Landlord::singleton()->branding_data;
        $tenantBranding = $this->resolveTenantForHost($host)?->branding_data ?? [];

        $tenantValue = $tenantBranding['pwa_icon'][$parameter] ?? null;

        return $tenantValue ?: ($landlordBranding['pwa_icon'][$parameter] ?? null);
    }

    public function resolveFaviconAsset(?string $host = null): ?string
    {
        $tenantBranding = $this->resolveTenantForHost($host)?->branding_data ?? [];
        $landlordBranding = Landlord::singleton()->branding_data ?? [];

        return $this->resolveFaviconAssetFromBranding($tenantBranding)
            ?? $this->resolveFaviconAssetFromBranding($landlordBranding);
    }

    public function resolveStoragePath(?string $uri): ?string
    {
        return $this->resolveStoragePathForHost($uri);
    }

    public function resolveStoragePathForHost(?string $uri, ?string $host = null): ?string
    {
        if (! $uri) {
            return null;
        }

        $brandables = $this->resolveBrandablesForHost($host);

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

    public function assetResponse(?string $path, ?string $host = null)
    {
        $localPath = $this->resolveStoragePathForHost($path, $host);

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

    /**
     * @return array<int, Landlord|Tenant>
     */
    private function resolveBrandablesForHost(?string $host): array
    {
        $tenant = $this->resolveTenantForHost($host);

        return $tenant instanceof Tenant
            ? [$tenant, Landlord::singleton()]
            : [Landlord::singleton()];
    }

    private function resolveTenantForHost(?string $host): ?Tenant
    {
        $normalizedHost = $this->normalizeHost($host);
        $currentTenant = Tenant::current();

        if ($normalizedHost === null) {
            return $currentTenant;
        }

        $landlordHost = $this->normalizeHost((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.url')));
        if ($landlordHost !== null && $normalizedHost === $landlordHost) {
            return null;
        }

        if ($landlordHost !== null) {
            $subdomainSuffix = '.'.$landlordHost;
            if (str_ends_with($normalizedHost, $subdomainSuffix)) {
                $subdomain = substr($normalizedHost, 0, -strlen($subdomainSuffix));
                if (is_string($subdomain) && $subdomain !== '' && ! str_contains($subdomain, '.')) {
                    $tenant = Tenant::query()->where('subdomain', $subdomain)->first();
                    if ($tenant instanceof Tenant) {
                        return $tenant;
                    }
                }
            }
        }

        return $this->tenantDomainResolverService->findTenantByDomain($normalizedHost) ?? $currentTenant;
    }

    private function normalizeHost(?string $host): ?string
    {
        $normalized = Str::lower(trim((string) $host));

        return $normalized === '' ? null : $normalized;
    }
}
