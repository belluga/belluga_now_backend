<?php

declare(strict_types=1);

namespace Belluga\Events\Console;

use Belluga\Events\Application\Events\EventOccurrenceOrphanInventoryService;
use Belluga\Events\Contracts\TenantExecutionContextContract;
use Illuminate\Console\Command;

class AuditEventOccurrenceOrphansCommand extends Command
{
    protected $signature = 'events:occurrences:audit-orphans {--all}';

    protected $description = 'Inventory orphan event_occurrences by tenant and classify live vs soft-deleted missing-parent rows.';

    public function handle(
        EventOccurrenceOrphanInventoryService $service,
        TenantExecutionContextContract $tenantExecutionContext
    ): int {
        $render = function (string $tenantSlug) use ($service): array {
            return [
                'tenant_slug' => $tenantSlug,
                ...$service->inventoryCurrentTenant(),
            ];
        };

        if ((bool) $this->option('all')) {
            $reports = [];
            $tenantExecutionContext->runForEachTenant(function () use (&$reports, $render): void {
                $tenantModel = $this->tenantModelClass();
                $tenant = $tenantModel::current();
                $reports[] = $render((string) ($tenant?->slug ?? ''));
            });

            $totals = [
                'scanned_occurrences' => array_sum(array_column(array_column($reports, 'totals'), 'scanned_occurrences')),
                'orphan_occurrences' => array_sum(array_column(array_column($reports, 'totals'), 'orphan_occurrences')),
                'active_bypass' => array_sum(array_column(array_column($reports, 'totals'), 'active_bypass')),
                'legacy_residue' => array_sum(array_column(array_column($reports, 'totals'), 'legacy_residue')),
            ];

            $this->line(json_encode([
                'tenant_scope' => 'all',
                'totals' => $totals,
                'reports' => $reports,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $tenantModel = $this->tenantModelClass();
        $tenant = $tenantModel::current();
        if (! $tenant) {
            $this->error('No current tenant. Set a tenant context first or use --all.');

            return self::FAILURE;
        }

        $this->line(json_encode(
            $render((string) ($tenant?->slug ?? '')),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    private function tenantModelClass(): string
    {
        /** @var class-string $tenantModel */
        $tenantModel = (string) config('multitenancy.tenant_model');

        return $tenantModel;
    }
}
