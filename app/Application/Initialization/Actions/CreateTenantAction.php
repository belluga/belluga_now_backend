<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Models\Landlord\Tenant;

class CreateTenantAction
{
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
            ]);
        } else {
            $tenant->name = $tenantData['name'];
            $tenant->save();
        }

        if (! empty($domains)) {
            $tenant->addDomains($domains);
        }

        return $tenant;
    }
}
