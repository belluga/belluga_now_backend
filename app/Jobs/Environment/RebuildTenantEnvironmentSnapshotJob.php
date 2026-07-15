<?php

declare(strict_types=1);

namespace App\Jobs\Environment;

use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class RebuildTenantEnvironmentSnapshotJob implements ShouldQueue, NotTenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $reason,
        private readonly array $context = [],
    ) {}

    public function handle(TenantEnvironmentSnapshotService $snapshotService): void
    {
        $tenantId = trim($this->tenantId);
        if ($tenantId === '') {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return;
        }

        $previousTenant = Tenant::current();
        $restorePreviousTenant = $previousTenant instanceof Tenant
            ? (string) $previousTenant->getKey()
            : '';

        $tenant->makeCurrent();

        try {
            $snapshotService->repair($tenant, $this->reason, $this->context);
        } finally {
            if ($restorePreviousTenant !== '' && $restorePreviousTenant !== $tenantId) {
                $restoredTenant = Tenant::query()->find($restorePreviousTenant);
                if ($restoredTenant instanceof Tenant) {
                    $restoredTenant->makeCurrent();

                    return;
                }
            }

            if ($restorePreviousTenant === $tenantId) {
                $tenant->makeCurrent();

                return;
            }

            Tenant::forgetCurrent();
        }
    }
}
