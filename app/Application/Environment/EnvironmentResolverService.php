<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Support\Str;
use App\Application\AccountProfiles\AccountProfileRegistryService;

class EnvironmentResolverService
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function resolve(array $input): array
    {
        $tenant = Tenant::current() ?? $this->locateTenant($input['app_domain'] ?? null);

        if ($tenant) {
            $tenant->makeCurrent();

            return $this->tenantEnvironment(
                tenant: $tenant,
                requestRoot: $input['request_root'] ?? null,
                requestHost: $input['request_host'] ?? null
            );
        }

        return $this->landlordEnvironment($input['request_root'] ?? null);
    }

    private function locateTenant(?string $appDomain): ?Tenant
    {
        if (! $appDomain) {
            return null;
        }

        return Tenant::where('app_domains', $appDomain)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantEnvironment(Tenant $tenant, ?string $requestRoot, ?string $requestHost): array
    {
        $landlord = Landlord::singleton();
        $pushSettings = TenantPushSettings::current();
        $settings = TenantSettings::current();
        $profileTypes = (new AccountProfileRegistryService())->registry();
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $landlord->branding_data,
            overrideArray: $tenant->branding_data ?? []
        );
        $mainDomain = $tenant->getMainDomain();
        $hasRelationDomains = $tenant->domains()->exists();
        $embeddedDomains = $tenant->getAttribute('domains');
        $hasEmbeddedDomains = is_array($embeddedDomains) && $embeddedDomains !== [];
        if (! $hasRelationDomains && ! $hasEmbeddedDomains) {
            $rootHost = $this->resolveRootHost($requestHost, $tenant->subdomain)
                ?? $this->resolveRootHost($requestRoot, $tenant->subdomain)
                ?? $this->resolveRootHost((string) config('app.url'), $tenant->subdomain);
            if ($rootHost) {
                $host = $tenant->subdomain . '.' . $rootHost;
                $mainDomain = $this->originWithRequestRoot(
                    requestRoot: $requestRoot,
                    host: $host,
                ) ?? $this->forceHttps($host);
            }
        }

        $domains = $tenant->domains()->get()->all();

        return [
            'tenant_id' => (string) $tenant->_id,
            'name' => $tenant->name,
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
            'main_domain' => $mainDomain,
            'domains' => $this->normalizeDomains($domains),
            'app_domains' => $tenant->app_domains,
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'telemetry' => $this->resolveTelemetryPayload($pushSettings),
            'firebase' => $pushSettings?->getAttribute('firebase') ?? [],
            'push' => $pushSettings?->getAttribute('push') ?? [],
            'profile_types' => $profileTypes,
            'settings' => [
                'map_ui' => $settings?->getAttribute('map_ui') ?? [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function landlordEnvironment(?string $requestRoot): array
    {
        $landlord = Landlord::singleton();
        $branding = $landlord->branding_data ?? [];

        $domainSource = $requestRoot ?? (string) config('app.url');
        $mainDomain = $this->forceHttps($domainSource);

        return [
            'name' => $landlord->name,
            'type' => 'landlord',
            'main_domain' => $mainDomain,
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
            'telemetry' => $this->resolveTelemetryPayload(null),
        ];
    }

    /**
     * @param array<string, mixed> $branding
     */
    private function resolveLogoUrl(array $branding, string $key): ?string
    {
        return $branding['logo_settings'][$key] ?? null;
    }

    /**
     * @param array<string, mixed> $branding
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
     * @param array<int, mixed> $domains
     * @return array<int, string>
     */
    private function normalizeDomains(array $domains): array
    {
        $normalized = array_map(static function ($domain): string {
            if (is_string($domain)) {
                return $domain;
            }

            return (string) ($domain['path'] ?? $domain->path ?? '');
        }, $domains);

        return array_values(array_filter($normalized, static fn (string $domain): bool => $domain !== ''));
    }

    private function forceHttps(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        return $normalized === '' ? null : 'https://' . $normalized;
    }

    private function resolveRootHost(?string $domain, ?string $tenantSubdomain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        if ($normalized === '') {
            return null;
        }

        if ($tenantSubdomain) {
            $prefix = Str::lower($tenantSubdomain) . '.';
            $normalizedLower = Str::lower($normalized);
            if (Str::startsWith($normalizedLower, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized === '' ? null : $normalized;
    }

    private function originWithRequestRoot(?string $requestRoot, string $host): ?string
    {
        if (! $requestRoot) {
            return null;
        }

        $parts = parse_url($requestRoot);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        if (! is_string($scheme) || $scheme === '') {
            return null;
        }

        $origin = $scheme . '://' . $host;
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTelemetryPayload(?TenantPushSettings $pushSettings): array
    {
        $defaultMinutes = (int) config('belluga_push_handler.telemetry.location_freshness_minutes', 5);
        $defaultMinutes = $defaultMinutes > 0 ? $defaultMinutes : 5;
        $trackers = [];
        $locationOverride = null;

        if ($pushSettings) {
            $rawTelemetry = $pushSettings->getAttribute('telemetry');
            if ($rawTelemetry instanceof \MongoDB\Model\BSONDocument || $rawTelemetry instanceof \MongoDB\Model\BSONArray) {
                $rawTelemetry = $rawTelemetry->getArrayCopy();
            } elseif ($rawTelemetry instanceof \Traversable) {
                $rawTelemetry = iterator_to_array($rawTelemetry);
            } elseif (is_object($rawTelemetry)) {
                $rawTelemetry = (array) $rawTelemetry;
            }

            if (is_array($rawTelemetry)) {
                if (array_key_exists('trackers', $rawTelemetry) ||
                    array_key_exists('location_freshness_minutes', $rawTelemetry)) {
                    $trackersRaw = $rawTelemetry['trackers'] ?? [];
                    $trackers = is_array($trackersRaw) ? $trackersRaw : [];
                    $locationOverride = $rawTelemetry['location_freshness_minutes'] ?? null;
                } else {
                    $trackers = $rawTelemetry;
                }
            }
        }

        if ($locationOverride === null && $pushSettings) {
            $raw = $pushSettings->getAttribute('telemetry_context');
            if ($raw instanceof \MongoDB\Model\BSONDocument || $raw instanceof \MongoDB\Model\BSONArray) {
                $raw = $raw->getArrayCopy();
            } elseif ($raw instanceof \Traversable) {
                $raw = iterator_to_array($raw);
            } elseif (is_object($raw)) {
                $raw = (array) $raw;
            }

            $context = is_array($raw) ? $raw : [];
            $locationOverride = $context['location_freshness_minutes'] ?? null;
        }

        $minutes = $defaultMinutes;
        if (is_numeric($locationOverride)) {
            $candidate = (int) $locationOverride;
            if ($candidate > 0) {
                $minutes = $candidate;
            }
        }

        return [
            'trackers' => $trackers,
            'location_freshness_minutes' => $minutes,
        ];
    }
}
