<?php

declare(strict_types=1);

namespace App\Application\Branding;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandingManifestService
{
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
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: Landlord::singleton()->branding_data ?? [],
            overrideArray: Tenant::current()?->branding_data ?? [],
        );

        return $this->resolveFaviconAssetFromBranding($branding);
    }

    public function resolveStoragePath(?string $uri): ?string
    {
        if (! $uri) {
            return null;
        }

        $urlPath = parse_url($uri, PHP_URL_PATH);

        return $urlPath ? Str::after($urlPath, '/storage/') : null;
    }

    public function assetResponse(?string $path)
    {
        $localPath = $this->resolveStoragePath($path);

        if ($localPath === null || ! Storage::disk('public')->exists($localPath)) {
            return response('', 404);
        }

        return response()->file(Storage::disk('public')->path($localPath));
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveFaviconAssetFromBranding(array $branding): ?string
    {
        $candidates = [
            $branding['logo_settings']['favicon_uri'] ?? null,
            $branding['pwa_icon']['icon192_uri'] ?? null,
            $branding['pwa_icon']['icon512_uri'] ?? null,
            $branding['pwa_icon']['source_uri'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
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
}
