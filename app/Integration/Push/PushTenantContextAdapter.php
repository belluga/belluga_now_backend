<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Contracts\PushTenantContextContract;

class PushTenantContextAdapter implements PushTenantContextContract
{
    public function currentTenantId(): ?string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return null;
        }

        return (string) $tenant->getAttribute('_id');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
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
