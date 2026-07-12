<?php

declare(strict_types=1);

namespace App\Integration\Settings;

use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Models\Landlord\Tenant;
use Belluga\Settings\Contracts\TenantEnvironmentSnapshotRepairContract;

class TenantEnvironmentSnapshotRepairAdapter implements TenantEnvironmentSnapshotRepairContract
{
    public function __construct(
        private readonly TenantEnvironmentSnapshotService $tenantEnvironmentSnapshotService,
    ) {}

    public function repairCurrentTenant(string $reason, array $context = []): void
    {
        $tenant = Tenant::resolve();

        $this->tenantEnvironmentSnapshotService->repair(
            $tenant->fresh() ?? $tenant,
            $reason,
            $context,
        );
    }
}
