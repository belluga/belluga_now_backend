<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Models\Landlord\Tenant;
use Belluga\Settings\Contracts\TenantScopeContextContract;

class TenantScopeContextAdapter implements TenantScopeContextContract
{
    public function runForTenantSlug(string $tenantSlug, callable $callback): mixed
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $callback();
        } finally {
            $tenant->forgetCurrent();
        }
    }
}
