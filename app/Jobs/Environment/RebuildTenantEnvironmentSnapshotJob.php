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
use Spatie\Multitenancy\Jobs\TenantAware;

class RebuildTenantEnvironmentSnapshotJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        private readonly string $reason,
        private readonly array $context = [],
    ) {}

    public function handle(TenantEnvironmentSnapshotService $snapshotService): void
    {
        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $snapshotService->repair($tenant, $this->reason, $this->context);
    }
}
