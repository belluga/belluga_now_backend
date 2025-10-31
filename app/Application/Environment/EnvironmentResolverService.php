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

            return $this->tenantEnvironment($tenant);
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
    private function tenantEnvironment(Tenant $tenant): array
    {
        $landlord = Landlord::singleton();

        return [
            'name' => $tenant->name,
            'type' => 'tenant',
            'subdomain' => $tenant->subdomain,
            'main_domain' => $tenant->getMainDomain(),
            'domains' => $tenant->domains()->get()->all(),
            'app_domains' => $tenant->app_domains,
            'theme_data_settings' => ArrayReplaceEmptyAware::mergeIfOverridenIsNotEmptyRecursive(
                mainArray: $landlord->branding_data['theme_data_settings'],
                overrideArray: $tenant->branding_data['theme_data_settings'] ?? []
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function landlordEnvironment(?string $requestRoot): array
    {
        $landlord = Landlord::singleton();

        $mainDomain = $requestRoot ? Str::replace('http://', 'https://', $requestRoot) : config('app.url');

        return [
            'name' => $landlord->name,
            'type' => 'landlord',
            'main_domain' => $mainDomain,
            'theme_data_settings' => $landlord->branding_data['theme_data_settings'],
        ];
    }
}
