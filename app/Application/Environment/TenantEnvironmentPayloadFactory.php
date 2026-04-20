<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Auth\TenantPublicAuthMethodResolver;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Telemetry\TelemetrySettingsKernelBridge;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Support\Str;

class TenantEnvironmentPayloadFactory
{
    public function __construct(
        private readonly TelemetrySettingsKernelBridge $telemetrySettings,
        private readonly TenantPublicAuthMethodResolver $tenantPublicAuthMethodResolver,
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly AccountProfileRegistryService $profileRegistryService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildLiveTenantPayload(
        Tenant $tenant,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        return $this->hydrateTenantPayload(
            tenant: $tenant,
            snapshot: $this->buildSnapshotSource($tenant),
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshotSource(Tenant $tenant): array
    {
        $landlord = Landlord::singleton();
        $settings = TenantSettings::current();
        $telemetry = $this->telemetrySettings->currentTelemetryConfig();
        $firebase = $this->pushSettings->currentFirebaseConfig();
        $push = $this->pushSettings->currentPushConfig();
        $tenantPublicAuth = $this->tenantPublicAuthMethodResolver->currentGovernance();
        $profileTypes = $this->profileRegistryService->registry();
        $tenantBranding = $this->normalizeSnapshotTenantBranding(
            $tenant,
            $this->normalizeBrandingData($tenant->branding_data ?? null),
        );
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $this->normalizeBrandingData($landlord->branding_data ?? null),
            overrideArray: $tenantBranding
        );

        return [
            'type' => 'tenant',
            'tenant_id' => (string) $tenant->getKey(),
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'canonical_main_domain' => $tenant->getMainDomain(),
            'domains' => $tenant->explicitDomains(),
            'app_domains' => $tenant->resolvedAppDomains(),
            'branding' => $branding,
            'tenant_branding' => $tenantBranding,
            'telemetry' => [
                'location_freshness_minutes' => $telemetry['location_freshness_minutes'],
                'trackers' => $telemetry['trackers'],
            ],
            'firebase' => $firebase,
            'push' => $push,
            'profile_types' => $profileTypes,
            'settings' => [
                'map_ui' => $settings?->getAttribute('map_ui') ?? [],
                'tenant_public_auth' => $tenantPublicAuth,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function normalizeSnapshotTenantBranding(Tenant $tenant, array $branding): array
    {
        $metadata = $this->normalizeBrandingData($branding['public_web_metadata'] ?? []);
        $defaultImage = trim((string) ($metadata['default_image'] ?? ''));

        if ($defaultImage === '') {
            return $branding;
        }

        $metadata['default_image'] = (string) (
            $this->brandingPublicWebMediaService->normalizePublicUrl(
                $tenant->getMainDomain(),
                $tenant,
                $defaultImage,
            ) ?? $defaultImage
        );
        $branding['public_web_metadata'] = $metadata;

        return $branding;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function hydrateTenantPayload(
        Tenant $tenant,
        array $snapshot,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        $branding = $this->normalizeBrandingData($snapshot['branding'] ?? []);
        $tenantBranding = $this->normalizeBrandingData($snapshot['tenant_branding'] ?? []);
        $canonicalMainDomain = trim((string) ($snapshot['canonical_main_domain'] ?? $tenant->getMainDomain()));
        $explicitDomains = $this->normalizeStringList($snapshot['domains'] ?? []);
        $resolvedRequestRoot = $this->normalizeRequestRoot($requestRoot);

        return [
            'tenant_id' => (string) ($snapshot['tenant_id'] ?? $tenant->getKey()),
            'name' => (string) ($snapshot['name'] ?? $tenant->name),
            'type' => 'tenant',
            'subdomain' => (string) ($snapshot['subdomain'] ?? $tenant->subdomain),
            'main_domain' => $this->resolveTenantMainDomain(
                tenantMainDomain: $canonicalMainDomain,
                explicitDomains: $explicitDomains,
                tenantSubdomain: (string) ($snapshot['subdomain'] ?? $tenant->subdomain),
                requestRoot: $requestRoot,
                requestHost: $requestHost,
            ),
            'landlord_domain' => $this->resolveLandlordDomain($requestRoot),
            'domains' => $explicitDomains,
            'app_domains' => $this->normalizeStringList($snapshot['app_domains'] ?? []),
            'theme_data_settings' => $this->normalizeBrandingData($branding['theme_data_settings'] ?? []),
            'branding_assets' => $this->resolveBrandingAssetState($branding),
            'public_web_metadata' => $this->resolvePublicWebMetadata(
                $tenant,
                $tenantBranding,
                $resolvedRequestRoot,
            ),
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'telemetry' => $this->normalizeTelemetry($snapshot['telemetry'] ?? []),
            'firebase' => $this->normalizeBrandingData($snapshot['firebase'] ?? []),
            'push' => $this->normalizeBrandingData($snapshot['push'] ?? []),
            'profile_types' => $this->normalizeProfileTypes(
                $snapshot['profile_types'] ?? [],
                $resolvedRequestRoot,
            ),
            'settings' => [
                'map_ui' => $this->normalizeBrandingData(
                    data_get($snapshot, 'settings.map_ui', [])
                ),
                'tenant_public_auth' => $this->normalizeBrandingData(
                    data_get($snapshot, 'settings.tenant_public_auth', [])
                ),
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $rawEntries
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProfileTypes(mixed $rawEntries, ?string $requestRoot): array
    {
        if (! is_array($rawEntries)) {
            return [];
        }

        $normalized = [];

        foreach ($rawEntries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalizedEntry = $entry;
            $normalizedEntry['visual'] = $this->normalizeProfileVisual(
                $entry['visual'] ?? null,
                $requestRoot,
            );
            $normalizedEntry['poi_visual'] = $this->normalizeProfileVisual(
                $entry['poi_visual'] ?? ($entry['visual'] ?? null),
                $requestRoot,
            );
            $normalized[] = $normalizedEntry;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeProfileVisual(mixed $rawVisual, ?string $requestRoot): ?array
    {
        if (! is_array($rawVisual)) {
            return null;
        }

        $visual = $rawVisual;
        $imageUrl = isset($visual['image_url']) && is_string($visual['image_url'])
            ? trim($visual['image_url'])
            : '';

        if ($imageUrl === '' || $requestRoot === null) {
            return $visual;
        }

        $path = parse_url($imageUrl, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return $visual;
        }

        $legacyPrefix = '/account-profile-types/';
        $canonicalPrefix = '/api/v1/media/account-profile-types/';
        if (! str_starts_with($path, $legacyPrefix) && ! str_starts_with($path, $canonicalPrefix)) {
            return $visual;
        }

        $query = parse_url($imageUrl, PHP_URL_QUERY);
        $visual['image_url'] = rtrim($requestRoot, '/').$path;
        if (is_string($query) && trim($query) !== '') {
            $visual['image_url'] .= '?'.$query;
        }

        return $visual;
    }

    /**
     * @param  array<string, mixed>  $telemetry
     * @return array<string, mixed>
     */
    private function normalizeTelemetry(mixed $telemetry): array
    {
        $normalized = $this->normalizeBrandingData($telemetry);
        $minutes = (int) ($normalized['location_freshness_minutes'] ?? 0);

        return [
            'location_freshness_minutes' => $minutes > 0
                ? $minutes
                : $this->defaultTelemetryLocationFreshnessMinutes(),
            'trackers' => is_array($normalized['trackers'] ?? null)
                ? array_values($normalized['trackers'])
                : [],
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveLogoUrl(array $branding, string $key): ?string
    {
        $value = $branding['logo_settings'][$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveIconUrl(array $branding, string $preferredKey): ?string
    {
        $logoValue = $branding['logo_settings'][$preferredKey] ?? null;

        if (is_string($logoValue) && trim($logoValue) !== '') {
            return $logoValue;
        }

        $pwaFallback = $branding['pwa_icon']['icon512_uri'] ?? null;

        return is_string($pwaFallback) && trim($pwaFallback) !== '' ? $pwaFallback : null;
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    private function resolveBrandingAssetState(array $branding): array
    {
        $hasDedicatedFavicon = $this->hasNonEmptyBrandingValue(
            $branding['logo_settings']['favicon_uri'] ?? null
        );

        return [
            'favicon' => [
                'has_dedicated_asset' => $hasDedicatedFavicon,
                'uses_pwa_fallback' => ! $hasDedicatedFavicon && $this->hasPwaFaviconFallback($branding),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $branding
     * @return array<string, string>
     */
    private function resolvePublicWebMetadata(
        Tenant $tenant,
        array $branding,
        ?string $requestRoot,
    ): array {
        $metadata = $this->normalizeBrandingData($branding['public_web_metadata'] ?? []);
        $defaultImage = trim((string) ($metadata['default_image'] ?? ''));

        if ($defaultImage !== '') {
            $defaultImage = (string) (
                $this->brandingPublicWebMediaService->normalizePublicUrl(
                    $requestRoot ?? config('app.url'),
                    $tenant,
                    $defaultImage,
                ) ?? ''
            );
        }

        return [
            'default_title' => (string) ($metadata['default_title'] ?? ''),
            'default_description' => (string) ($metadata['default_description'] ?? ''),
            'default_image' => $defaultImage,
        ];
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function hasPwaFaviconFallback(array $branding): bool
    {
        $pwaIcon = $branding['pwa_icon'] ?? null;

        if (! is_array($pwaIcon)) {
            return false;
        }

        foreach (['icon192_uri', 'icon512_uri', 'source_uri'] as $key) {
            if ($this->hasNonEmptyBrandingValue($pwaIcon[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasNonEmptyBrandingValue(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeBrandingData(mixed $branding): array
    {
        if (is_array($branding)) {
            return $branding;
        }

        if ($branding instanceof \Traversable) {
            return iterator_to_array($branding);
        }

        if (is_object($branding) && method_exists($branding, 'toArray')) {
            $normalized = $branding->toArray();

            return is_array($normalized) ? $normalized : [];
        }

        if (is_object($branding)) {
            return (array) $branding;
        }

        return [];
    }

    /**
     * Web: use current tenant host as main_domain.
     * Mobile (resolved via app_domain on landlord host): keep canonical tenant main domain.
     *
     * @param  array<int, string>  $explicitDomains
     */
    private function resolveTenantMainDomain(
        string $tenantMainDomain,
        array $explicitDomains,
        ?string $tenantSubdomain,
        ?string $requestRoot,
        ?string $requestHost
    ): string {
        $normalizedRequestRoot = $this->normalizeRequestRoot($requestRoot);
        $normalizedRequestHost = $this->normalizeHost($requestHost);
        if ($normalizedRequestRoot === null || $normalizedRequestHost === null) {
            return $tenantMainDomain;
        }

        $allowedHosts = [];
        foreach ($explicitDomains as $domain) {
            $host = $this->normalizeHost(parse_url($this->forceHttps($domain), PHP_URL_HOST));
            if ($host !== null) {
                $allowedHosts[$host] = true;
            }
        }

        $implicitSubdomainHost = $this->implicitTenantSubdomainHost($tenantSubdomain);
        if ($implicitSubdomainHost !== null) {
            $allowedHosts[$implicitSubdomainHost] = true;
        }

        if (! isset($allowedHosts[$normalizedRequestHost])) {
            return $tenantMainDomain;
        }

        return $normalizedRequestRoot;
    }

    private function resolveLandlordDomain(?string $requestRoot): ?string
    {
        $configured = $this->forceHttps((string) config('app.url'));
        if ($configured !== null) {
            return $configured;
        }

        return $this->normalizeRequestRoot($requestRoot);
    }

    private function normalizeRequestRoot(?string $requestRoot): ?string
    {
        if (! is_string($requestRoot)) {
            return null;
        }

        $trimmed = trim($requestRoot);
        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    private function normalizeHost(mixed $host): ?string
    {
        if (! is_string($host)) {
            return null;
        }

        $normalized = trim(Str::lower($host));

        return $normalized === '' ? null : $normalized;
    }

    private function forceHttps(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        return $normalized === '' ? null : 'https://'.$normalized;
    }

    private function implicitTenantSubdomainHost(?string $subdomain): ?string
    {
        $normalizedSubdomain = $this->normalizeHost($subdomain);
        if ($normalizedSubdomain === null) {
            return null;
        }

        $configuredRootHost = $this->normalizeHost(parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($configuredRootHost === null) {
            $configuredRootHost = $this->normalizeHost(
                trim(Str::replace(['https://', 'http://'], '', (string) config('app.url')), '/')
            );
        }

        if ($configuredRootHost === null) {
            return null;
        }

        return sprintf('%s.%s', $normalizedSubdomain, $configuredRootHost);
    }

    private function defaultTelemetryLocationFreshnessMinutes(): int
    {
        $minutes = (int) config('telemetry.location_freshness_minutes', 5);

        return $minutes > 0 ? $minutes : 5;
    }
}
