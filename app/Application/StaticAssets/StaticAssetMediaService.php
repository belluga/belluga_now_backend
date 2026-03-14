<?php

declare(strict_types=1);

namespace App\Application\StaticAssets;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StaticAssetMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/static-assets';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/static-assets';

    public function __construct(
        private readonly TenantDomainResolverService $tenantDomainResolver,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function applyUploads(Request $request, StaticAsset $asset): array
    {
        $updates = [];
        $baseUrl = $request->getSchemeAndHttpHost();
        $removeAvatar = $request->boolean('remove_avatar');
        $removeCover = $request->boolean('remove_cover');

        if ($request->hasFile('avatar') || $request->hasFile('cover') || $removeAvatar || $removeCover) {
            $asset->updated_at = now();
        }

        if ($request->hasFile('avatar')) {
            $updates['avatar_url'] = $this->storeFile(
                $request->file('avatar'),
                $asset,
                'avatar',
                $baseUrl
            );
        } elseif ($removeAvatar) {
            $this->deleteExisting($asset, 'avatar', $baseUrl);
            $updates['avatar_url'] = null;
        }

        if ($request->hasFile('cover')) {
            $updates['cover_url'] = $this->storeFile(
                $request->file('cover'),
                $asset,
                'cover',
                $baseUrl
            );
        } elseif ($removeCover) {
            $this->deleteExisting($asset, 'cover', $baseUrl);
            $updates['cover_url'] = null;
        }

        if ($updates !== []) {
            $asset->fill($updates);
            $asset->save();
            $asset->refresh();
        }

        return $updates;
    }

    private function storeFile(
        UploadedFile $file,
        StaticAsset $asset,
        string $kind,
        string $baseUrl
    ): string {
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = "{$kind}.{$extension}";

        $this->deleteExisting($asset, $kind, $baseUrl);

        Storage::disk('public')->putFileAs($this->baseDirectory($asset, $baseUrl), $file, $fileName);

        return $this->buildPublicUrl($baseUrl, $asset, $kind);
    }

    public function resolveMediaPath(StaticAsset $asset, string $kind): ?string
    {
        return $this->resolveMediaPathForBaseUrl($asset, $kind, null);
    }

    public function resolveMediaPathForBaseUrl(
        StaticAsset $asset,
        string $kind,
        ?string $baseUrl,
    ): ?string
    {
        $baseDir = $this->baseDirectory($asset, $baseUrl);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(
        string $baseUrl,
        StaticAsset $asset,
        string $kind,
        string|int|null $version = null,
    ): string
    {
        $assetId = (string) $asset->_id;
        $base = rtrim($baseUrl, '/');
        $resolvedVersion = $version ?? ($asset->updated_at?->getTimestamp() ?? time());

        return "{$base}".self::CANONICAL_PUBLIC_PATH_PREFIX."/{$assetId}/{$kind}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        StaticAsset $asset,
        string $kind,
        ?string $rawUrl,
    ): ?string {
        $value = is_string($rawUrl) ? trim($rawUrl) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $value;
        }

        $assetId = (string) $asset->_id;
        $legacyPath = self::LEGACY_PUBLIC_PATH_PREFIX."/{$assetId}/{$kind}";
        $canonicalPath = self::CANONICAL_PUBLIC_PATH_PREFIX."/{$assetId}/{$kind}";
        if ($path !== $legacyPath && $path !== $canonicalPath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? ($asset->updated_at?->getTimestamp() ?? time());

        return $this->buildPublicUrl($baseUrl, $asset, $kind, $version);
    }

    private function deleteExisting(StaticAsset $asset, string $kind, ?string $baseUrl = null): void
    {
        $baseDir = $this->baseDirectory($asset, $baseUrl);
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$baseDir}/{$kind}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'webp'];
    }

    private function baseDirectory(StaticAsset $asset, ?string $baseUrl = null): string
    {
        $tenantSlug = $this->resolveTenantSlug($baseUrl) ?? Tenant::current()?->slug ?? 'landlord';
        $assetId = (string) $asset->_id;

        return "tenants/{$tenantSlug}/static_assets/{$assetId}";
    }

    private function extractVersionFromUri(string $value): ?string
    {
        $query = parse_url($value, PHP_URL_QUERY);
        if (! is_string($query) || trim($query) === '') {
            return null;
        }

        parse_str($query, $parameters);
        $version = $parameters['v'] ?? null;
        if (! is_scalar($version)) {
            return null;
        }

        $normalized = trim((string) $version);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveTenantSlug(?string $baseUrl): ?string
    {
        $host = $this->resolveHost($baseUrl);
        if ($host !== null) {
            $tenant = $this->tenantDomainResolver->findTenantByDomain($host);
            if ($tenant !== null) {
                return $tenant->slug;
            }

            $subdomainTenant = $this->resolveTenantBySubdomain($host);
            if ($subdomainTenant !== null) {
                return $subdomainTenant->slug;
            }
        }

        return Tenant::current()?->slug;
    }

    private function resolveHost(?string $baseUrl): ?string
    {
        $fromBaseUrl = $this->extractHost($baseUrl);
        if ($fromBaseUrl !== null) {
            return $fromBaseUrl;
        }

        return $this->extractHost(request()->getSchemeAndHttpHost());
    }

    private function extractHost(?string $baseUrl): ?string
    {
        $value = is_string($baseUrl) ? trim($baseUrl) : '';
        if ($value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower(trim($host));
    }

    private function resolveTenantBySubdomain(string $host): ?Tenant
    {
        $normalizedHost = strtolower(trim($host));
        if ($normalizedHost === '' || filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $parts = explode('.', $normalizedHost);
        $subdomain = trim($parts[0] ?? '');
        if ($subdomain === '') {
            return null;
        }

        return Tenant::query()->where('subdomain', $subdomain)->first();
    }
}
