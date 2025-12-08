<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Support\Helpers\ArrayReplaceEmptyAware;
use Illuminate\Support\Str;

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
        $branding = ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
            mainArray: $landlord->branding_data,
            overrideArray: $tenant->branding_data ?? []
        );
        $mainDomain = $tenant->getMainDomain();
        if ($requestRoot) {
            $mainDomain = $this->forceHttps($requestRoot);
        } elseif ($requestHost) {
            $mainDomain = $this->forceHttps($requestHost);
        }

        return [
            'name' => $tenant->name,
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
            'main_domain' => $mainDomain,
            'domains' => $tenant->domains()->get()->all(),
            'app_domains' => $tenant->app_domains,
            'theme_data_settings' => $branding['theme_data_settings'] ?? [],
            'main_logo_light_url' => $this->resolveLogoUrl($branding, 'light_logo_uri'),
            'main_logo_dark_url' => $this->resolveLogoUrl($branding, 'dark_logo_uri'),
            'main_icon_light_url' => $this->resolveIconUrl($branding, 'light_icon_uri'),
            'main_icon_dark_url' => $this->resolveIconUrl($branding, 'dark_icon_uri'),
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

    private function forceHttps(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $normalized = Str::replace(['http://', 'https://'], '', $domain);
        $normalized = trim($normalized, '/');

        return $normalized === '' ? null : 'https://' . $normalized;
    }
}
