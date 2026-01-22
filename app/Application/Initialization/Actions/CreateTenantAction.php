<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Organization;

class CreateTenantAction
{
    public function __construct(
        private readonly AccountProfileRegistrySeeder $registrySeeder
    ) {
    }

    /**
     * @param array<string, mixed> $tenantData
     * @param array<int, string> $domains
     */
    public function execute(array $tenantData, array $domains = []): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', $tenantData['subdomain'])
            ->first();

        if (! $tenant) {
            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'subdomain' => $tenantData['subdomain'],
                'organization_id' => $tenantData['organization_id'] ?? null,
            ]);
        } else {
            $tenant->name = $tenantData['name'];
            if (array_key_exists('organization_id', $tenantData)) {
                $tenant->organization_id = $tenantData['organization_id'];
            }
            $tenant->save();
        }

        if (! empty($domains)) {
            $tenant->addDomains($domains);
        }

        $tenant->makeCurrent();
        $this->registrySeeder->ensureDefaults();
        $this->ensureTenantOrganization($tenant);
        $tenant->forgetCurrent();

        return $tenant;
    }

    private function ensureTenantOrganization(Tenant $tenant): void
    {
        $organization = null;

        if (! empty($tenant->organization_id)) {
            $organization = Organization::query()
                ->where('_id', $tenant->organization_id)
                ->first();
        }

        if (! $organization) {
            $organization = Organization::create([
                'name' => $tenant->name,
                'description' => 'Tenant organization',
                'created_by' => (string) $tenant->_id,
                'created_by_type' => 'landlord',
                'updated_by' => (string) $tenant->_id,
                'updated_by_type' => 'landlord',
            ]);

            $tenant->organization_id = (string) $organization->_id;
            $tenant->save();
        }
    }
}
