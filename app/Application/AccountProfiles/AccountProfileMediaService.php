<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AccountProfileMediaService
{
    private const LEGACY_PUBLIC_PATH_PREFIX = '/account-profiles';

    private const CANONICAL_PUBLIC_PATH_PREFIX = '/api/v1/media/account-profiles';

    public function __construct(
        private readonly TenantDomainResolverService $tenantDomainResolver,
    ) {}

    /**
     * @return array<string, string>
     */
    public function applyUploads(Request $request, AccountProfile $profile): array
    {
        $updates = [];
        $baseUrl = $request->getSchemeAndHttpHost();
        $removeAvatar = $request->boolean('remove_avatar');
        $removeCover = $request->boolean('remove_cover');

        if ($request->hasFile('avatar') || $request->hasFile('cover') || $removeAvatar || $removeCover) {
            $profile->updated_at = now();
        }

        if ($request->hasFile('avatar')) {
            $updates['avatar_url'] = $this->storeFile(
                $request->file('avatar'),
                $profile,
                'avatar',
                $baseUrl
            );
        } elseif ($removeAvatar) {
            $this->deleteExisting($profile, 'avatar', $baseUrl);
            $updates['avatar_url'] = null;
        }

        if ($request->hasFile('cover')) {
            $updates['cover_url'] = $this->storeFile(
                $request->file('cover'),
                $profile,
                'cover',
                $baseUrl
            );
        } elseif ($removeCover) {
            $this->deleteExisting($profile, 'cover', $baseUrl);
            $updates['cover_url'] = null;
        }

        if (! empty($updates)) {
            $profile->fill($updates);
            $profile->save();
            $profile->refresh();
        }

        return $updates;
    }

    private function storeFile(
        UploadedFile $file,
        AccountProfile $profile,
        string $kind,
        string $baseUrl
    ): string {
        $extension = $file->getClientOriginalExtension() ?: 'png';
        $fileName = "{$kind}.{$extension}";

        $this->deleteExisting($profile, $kind, $baseUrl);

        Storage::disk('public')->putFileAs($this->baseDirectory($profile, $baseUrl), $file, $fileName);

        return $this->buildPublicUrl($baseUrl, $profile, $kind);
    }

    public function resolveMediaPath(AccountProfile $profile, string $kind): ?string
    {
        return $this->resolveMediaPathForBaseUrl($profile, $kind, null);
    }

    public function resolveMediaPathForBaseUrl(
        AccountProfile $profile,
        string $kind,
        ?string $baseUrl,
    ): ?string {
        $baseDir = $this->baseDirectory($profile, $baseUrl);
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
        AccountProfile $profile,
        string $kind,
        string|int|null $version = null,
    ): string
    {
        $profileId = (string) $profile->_id;
        $base = rtrim($baseUrl, '/');
        $resolvedVersion = $version ?? ($profile->updated_at?->getTimestamp() ?? time());

        return "{$base}".self::CANONICAL_PUBLIC_PATH_PREFIX."/{$profileId}/{$kind}?v={$resolvedVersion}";
    }

    public function normalizePublicUrl(
        string $baseUrl,
        AccountProfile $profile,
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

        $profileId = (string) $profile->_id;
        $legacyPath = self::LEGACY_PUBLIC_PATH_PREFIX."/{$profileId}/{$kind}";
        $canonicalPath = self::CANONICAL_PUBLIC_PATH_PREFIX."/{$profileId}/{$kind}";
        if ($path !== $legacyPath && $path !== $canonicalPath) {
            return $value;
        }

        $version = $this->extractVersionFromUri($value)
            ?? ($profile->updated_at?->getTimestamp() ?? time());

        return $this->buildPublicUrl($baseUrl, $profile, $kind, $version);
    }

    private function deleteExisting(AccountProfile $profile, string $kind, ?string $baseUrl = null): void
    {
        $baseDir = $this->baseDirectory($profile, $baseUrl);
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

    private function baseDirectory(AccountProfile $profile, ?string $baseUrl = null): string
    {
        $tenantSlug = $this->resolveTenantSlug($baseUrl) ?? Tenant::current()?->slug ?? 'landlord';
        $profileId = (string) $profile->_id;

        return "tenants/{$tenantSlug}/account_profiles/{$profileId}";
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
