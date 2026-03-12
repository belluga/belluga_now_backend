<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class MapFilterImageStorageService
{
    public function __construct(
        private readonly TenantDomainResolverService $tenantDomainResolver,
    ) {}

    /**
     * @return array{key: string, image_uri: string}
     */
    public function store(
        string $key,
        UploadedFile $image,
        string $baseUrl
    ): array {
        $normalizedKey = $this->normalizeKey($key);
        $extension = $this->resolveExtension($image);
        $directory = $this->baseDirectory($baseUrl);

        $this->deleteExisting($directory, $normalizedKey);

        $fileName = "{$normalizedKey}.{$extension}";
        Storage::disk('public')->putFileAs($directory, $image, $fileName);

        $relativePath = "{$directory}/{$fileName}";
        $version = $this->resolveMediaVersion($relativePath);

        return [
            'key' => $normalizedKey,
            'image_uri' => $this->buildPublicUrl($baseUrl, $normalizedKey, $version),
        ];
    }

    public function normalizeKey(string $rawKey): string
    {
        return strtolower(trim($rawKey));
    }

    public function resolveMediaPath(string $key): ?string
    {
        return $this->resolveMediaPathForBaseUrl($key, null);
    }

    public function resolveMediaPathForBaseUrl(
        string $key,
        ?string $baseUrl,
    ): ?string {
        $normalizedKey = $this->normalizeKey($key);
        $directory = $this->baseDirectory($baseUrl);

        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$directory}/{$normalizedKey}.{$extension}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public function buildPublicUrl(
        string $baseUrl,
        string $key,
        string|int|null $version = null,
    ): string {
        $base = rtrim($baseUrl, '/');
        $normalizedKey = $this->normalizeKey($key);
        $resolvedVersion = $version ?? time();

        return "{$base}/api/v1/media/map-filters/{$normalizedKey}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        string $key,
        ?string $rawImageUri,
    ): ?string {
        $value = is_string($rawImageUri) ? trim($rawImageUri) : '';
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $value;
        }

        $normalizedKey = $this->normalizeKey($key);
        $legacyPath = "/map-filters/{$normalizedKey}/image";
        $canonicalPath = "/api/v1/media/map-filters/{$normalizedKey}";

        if ($path !== $legacyPath && $path !== $canonicalPath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? $this->resolveCurrentVersion($normalizedKey, $baseUrl);

        return $this->buildPublicUrl($baseUrl, $normalizedKey, $version);
    }

    private function baseDirectory(?string $baseUrl): string
    {
        $tenantSlug = $this->resolveTenantSlug($baseUrl) ?? 'landlord';

        return "tenants/{$tenantSlug}/map_filters";
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        if ($mime === 'image/jpeg') {
            return 'jpg';
        }
        if ($mime === 'image/webp') {
            return 'webp';
        }

        return 'png';
    }

    private function resolveMediaVersion(string $relativePath): string
    {
        $absolutePath = Storage::disk('public')->path($relativePath);
        $fingerprint = @md5_file($absolutePath);

        if (is_string($fingerprint) && $fingerprint !== '') {
            return substr($fingerprint, 0, 16);
        }

        return (string) Storage::disk('public')->lastModified($relativePath);
    }

    private function resolveCurrentVersion(
        string $key,
        ?string $baseUrl,
    ): ?string {
        $path = $this->resolveMediaPathForBaseUrl($key, $baseUrl);
        if ($path === null) {
            return null;
        }

        return $this->resolveMediaVersion($path);
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

    private function deleteExisting(string $directory, string $key): void
    {
        foreach ($this->allowedExtensions() as $extension) {
            $path = "{$directory}/{$key}.{$extension}";
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
        return ['png', 'jpg', 'jpeg', 'webp'];
    }
}
