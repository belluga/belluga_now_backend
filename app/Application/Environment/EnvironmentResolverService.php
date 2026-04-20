<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Application\Auth\TenantPublicAuthMethodResolver;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Telemetry\TelemetrySettingsKernelBridge;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Support\Str;

class EnvironmentResolverService
{
    public function __construct(
        private readonly TelemetrySettingsKernelBridge $telemetrySettings,
        private readonly TenantPublicAuthMethodResolver $tenantPublicAuthMethodResolver,
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly TenantAppDomainResolverService $appDomainResolver,
        private readonly AccountProfileRegistryService $profileRegistryService,
        private readonly BrandingPublicWebMediaService $brandingPublicWebMediaService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input): array
    {
        $currentTenant = Tenant::current();
        $tenant = $currentTenant?->fresh();
        if ($tenant === null) {
            $appDomain = $input['app_domain'] ?? null;
            $tenant = $this->locateTenant(is_string($appDomain) ? $appDomain : null);
        }
        $requestHost = $input['request_host'] ?? null;

        if ($tenant) {
            $tenant->makeCurrent();

            return $this->tenantEnvironment(
                tenant: $tenant,
                requestRoot: $input['request_root'] ?? null,
                requestHost: is_string($requestHost) ? $requestHost : null,
            );
        }

        return $this->landlordEnvironment($input['request_root'] ?? null);
    }

    private function locateTenant(?string $appDomain): ?Tenant
    {
        if (! $appDomain) {
            return null;
        }

        return $this->appDomainResolver->findTenantByIdentifier($appDomain);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantEnvironment(Tenant $tenant, ?string $requestRoot, ?string $requestHost): array
    {
        $landlord = Landlord::singleton();
        $settings = TenantSettings::current();
        $telemetry = $this->telemetrySettings->currentTelemetryConfig();
        $firebase = $this->pushSettings->currentFirebaseConfig();
        $push = $this->pushSettings->currentPushConfig();
        $tenantPublicAuth = $this->tenantPublicAuthMethodResolver->currentGovernance();
        $profileTypes = $this->profileRegistryService->registry($requestRoot);
        $tenantBranding = $this->normalizeBrandingData($tenant->branding_data ?? null);
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $this->normalizeBrandingData($landlord->branding_data ?? null),
            overrideArray: $tenantBranding
        );
        $canonicalTenantMainDomain = $tenant->getMainDomain();
        $explicitDomains = $tenant->explicitDomains();
        $mainDomain = $this->resolveTenantMainDomain(
            tenantMainDomain: $canonicalTenantMainDomain,
            explicitDomains: $explicitDomains,
            tenantSubdomain: $tenant->subdomain,
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );

        return [
            'tenant_id' => (string) $tenant->_id,
            'name' => $tenant->name,
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
            'main_domain' => $mainDomain,
            'landlord_domain' => $this->resolveLandlordDomain($requestRoot),
            'domains' => $explicitDomains,
            'app_domains' => $tenant->resolvedAppDomains(),
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'branding_assets' => $this->resolveBrandingAssetState($branding),
            'public_web_metadata' => $this->resolvePublicWebMetadata(
                $tenant,
                $tenantBranding,
                $requestRoot
            ),
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
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
     * @return array<string, mixed>
     */
    private function landlordEnvironment(?string $requestRoot): array
    {
        $landlord = Landlord::singleton();
        $branding = $this->normalizeBrandingData($landlord->branding_data ?? null);

        $mainDomain = $this->normalizeRequestRoot($requestRoot)
            ?? $this->forceHttps((string) config('app.url'));

        return [
            'name' => $landlord->name,
            'type' => 'landlord',
            'main_domain' => $mainDomain,
            'landlord_domain' => $mainDomain,
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'branding_assets' => $this->resolveBrandingAssetState($branding),
            'public_web_metadata' => $this->resolvePublicWebMetadata(
                $landlord,
                $branding,
                $requestRoot
            ),
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'telemetry' => [
                'location_freshness_minutes' => $this->defaultTelemetryLocationFreshnessMinutes(),
                'trackers' => [],
            ],
            'settings' => [
                'tenant_public_auth' => $this->tenantPublicAuthMethodResolver->currentLandlordGovernance(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveLogoUrl(array $branding, string $key): ?string
    {
        return $branding['logo_settings'][$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function resolveIconUrl(array $branding, string $preferredKey): ?string
    {
        $logoValue = $branding['logo_settings'][$preferredKey] ?? null;

        if ($logoValue) {
            return $logoValue;
        }

        return $branding['pwa_icon']['icon512_uri'] ?? null;
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
        Tenant|Landlord $brandable,
        array $branding,
        ?string $requestRoot,
    ): array
    {
        $metadata = $branding['public_web_metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $defaultImage = (string) ($metadata['default_image'] ?? '');
        if ($defaultImage !== '') {
            $defaultImage = (string) (
                $this->brandingPublicWebMediaService->normalizePublicUrl(
                    $this->normalizeRequestRoot($requestRoot) ?? config('app.url'),
                    $brandable,
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
