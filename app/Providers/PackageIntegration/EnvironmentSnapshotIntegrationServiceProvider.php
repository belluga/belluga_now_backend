<?php

declare(strict_types=1);

namespace App\Providers\PackageIntegration;

use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use App\Models\Tenants\TenantProfileType;
use App\Models\Tenants\TenantSettings as TenantEnvironmentSettings;
use Belluga\Settings\Models\Tenants\TenantSettings as TenantSettingsKernel;
use Illuminate\Support\ServiceProvider;

class EnvironmentSnapshotIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TenantSettingsKernel::saved(function (TenantSettingsKernel $settings): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'settings_kernel_updated',
                ['document' => (string) $settings->getKey()],
            );
        });

        TenantSettingsKernel::deleted(function (TenantSettingsKernel $settings): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'settings_kernel_deleted',
                ['document' => (string) $settings->getKey()],
            );
        });

        TenantEnvironmentSettings::saved(function (TenantEnvironmentSettings $settings): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'tenant_environment_settings_updated',
                ['document' => (string) $settings->getKey()],
            );
        });

        TenantEnvironmentSettings::deleted(function (TenantEnvironmentSettings $settings): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'tenant_environment_settings_deleted',
                ['document' => (string) $settings->getKey()],
            );
        });

        TenantProfileType::saved(function (TenantProfileType $profileType): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'profile_type_registry_updated',
                ['profile_type' => (string) $profileType->type],
            );
        });

        TenantProfileType::deleted(function (TenantProfileType $profileType): void {
            if (! Tenant::current()) {
                return;
            }

            $this->snapshotService()->dispatchRefreshForCurrentTenant(
                'profile_type_registry_deleted',
                ['profile_type' => (string) $profileType->type],
            );
        });

        Tenant::saved(function (Tenant $tenant): void {
            $this->dispatchRefreshForTenantRecord(
                $tenant,
                'tenant_record_updated',
                ['tenant_id' => (string) $tenant->getKey()],
            );
        });

        Domains::saved(function (Domains $domain): void {
            $this->dispatchRefreshForDomainRecord(
                $domain,
                'tenant_domain_updated',
                [
                    'domain_id' => (string) $domain->getKey(),
                    'domain_type' => (string) ($domain->type ?? ''),
                ],
            );
        });

        Domains::deleted(function (Domains $domain): void {
            $this->dispatchRefreshForDomainRecord(
                $domain,
                'tenant_domain_deleted',
                [
                    'domain_id' => (string) $domain->getKey(),
                    'domain_type' => (string) ($domain->type ?? ''),
                ],
            );
        });

        Domains::restored(function (Domains $domain): void {
            $this->dispatchRefreshForDomainRecord(
                $domain,
                'tenant_domain_restored',
                [
                    'domain_id' => (string) $domain->getKey(),
                    'domain_type' => (string) ($domain->type ?? ''),
                ],
            );
        });

        Landlord::saved(function (Landlord $landlord): void {
            $this->snapshotService()->dispatchRefreshForAllTenants(
                'landlord_branding_updated',
                ['landlord_id' => (string) $landlord->getKey()],
            );
        });

        Landlord::deleted(function (Landlord $landlord): void {
            $this->snapshotService()->dispatchRefreshForAllTenants(
                'landlord_branding_deleted',
                ['landlord_id' => (string) $landlord->getKey()],
            );
        });

        TenantEnvironmentSnapshot::saved(static function (): void {
            // Snapshot saves are intentionally not rebound into another rebuild trigger.
        });
    }

    private function snapshotService(): TenantEnvironmentSnapshotService
    {
        return $this->app->make(TenantEnvironmentSnapshotService::class);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchRefreshForTenantRecord(
        Tenant $tenant,
        string $reason,
        array $context = [],
    ): void {
        $currentTenant = Tenant::current();
        if ($currentTenant && (string) $currentTenant->getKey() === (string) $tenant->getKey()) {
            $this->snapshotService()->dispatchRefreshForCurrentTenant($reason, $context);

            return;
        }

        $this->snapshotService()->dispatchRefreshForTenant($tenant, $reason, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchRefreshForDomainRecord(
        Domains $domain,
        string $reason,
        array $context = [],
    ): void {
        $tenant = $domain->tenant()->withTrashed()->first();
        if ($tenant instanceof Tenant) {
            $this->dispatchRefreshForTenantRecord($tenant, $reason, $context);

            return;
        }

        if (! Tenant::current()) {
            return;
        }

        $this->snapshotService()->dispatchRefreshForCurrentTenant($reason, $context);
    }
}
