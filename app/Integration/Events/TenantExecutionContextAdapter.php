<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Landlord\Tenant;
use Belluga\Events\Contracts\TenantExecutionContextContract;

class TenantExecutionContextAdapter implements TenantExecutionContextContract
{
    public function runForEachTenant(callable $callback): void
    {
        Tenant::query()
            ->get()
            ->each(static function (Tenant $tenant) use ($callback): void {
                $tenant->makeCurrent();

                try {
                    $callback();
                } finally {
                    $tenant->forgetCurrent();
                }
            });
    }
}
