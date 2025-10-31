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
        $tenant = Tenant::create([
            'name' => $tenantData['name'],
            'subdomain' => $tenantData['subdomain'],
        ]);

        if (! empty($domains)) {
            $tenant->addDomains($domains);
        }

        return $tenant;
    }
}
