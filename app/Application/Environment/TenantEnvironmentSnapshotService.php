<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Jobs\Environment\RebuildTenantEnvironmentSnapshotJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class TenantEnvironmentSnapshotService
{
    public const SCHEMA_VERSION = 1;

    /**
     * @var array<string, bool>
     */
    private array $dispatchMemo = [];

    public function __construct(
        private readonly TenantEnvironmentPayloadFactory $payloadFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function readResolvedPayload(
        Tenant $tenant,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        $snapshot = TenantEnvironmentSnapshot::current();

        if ($this->needsRepair($snapshot)) {
            $reason = $snapshot === null ? 'missing_snapshot' : 'version_drift';
            Log::warning('tenant_environment_snapshot_repair_requested', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'schema_version' => $snapshot?->schema_version,
                'expected_schema_version' => self::SCHEMA_VERSION,
            ]);

            try {
                $snapshot = $this->repair($tenant, $reason, [
                    'trigger' => 'read_path',
                    'request_host' => $requestHost,
                ]);
            } catch (\Throwable $exception) {
                if ($snapshot instanceof TenantEnvironmentSnapshot && $this->hasUsableSnapshot($snapshot)) {
                    Log::warning('tenant_environment_snapshot_repair_failed_serving_last_valid', [
                        'tenant_id' => (string) $tenant->getKey(),
                        'tenant_slug' => (string) $tenant->slug,
                        'reason' => $reason,
                        'error' => $exception->getMessage(),
                        'snapshot_version' => (string) ($snapshot->snapshot_version ?? ''),
                        'built_at' => $snapshot->built_at?->toIso8601String(),
                    ]);

                    return $this->hydrateSnapshot($tenant, $snapshot, $requestRoot, $requestHost);
                }

                Log::error('tenant_environment_snapshot_repair_failed_falling_back_live', [
                    'tenant_id' => (string) $tenant->getKey(),
                    'tenant_slug' => (string) $tenant->slug,
                    'reason' => $reason,
                    'error' => $exception->getMessage(),
                ]);

                return $this->payloadFactory->buildLiveTenantPayload($tenant, $requestRoot, $requestHost);
            }
        }

        return $this->hydrateSnapshot($tenant, $snapshot, $requestRoot, $requestHost);
    }

    public function dispatchRefreshForCurrentTenant(string $reason, array $context = []): void
    {
        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $this->dispatchRefreshForTenantId((string) $tenant->getKey(), $reason, $context);
    }

    public function dispatchRefreshForTenant(Tenant $tenant, string $reason, array $context = []): void
    {
        $this->dispatchRefreshForTenantId((string) $tenant->getKey(), $reason, $context);
    }

    public function dispatchRefreshForAllTenants(string $reason, array $context = []): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $this->dispatchRefreshForTenant($tenant, $reason, $context);
        }
    }

    public function repair(Tenant $tenant, string $reason, array $context = []): TenantEnvironmentSnapshot
    {
        $startedAt = now();
        $started = microtime(true);
        $snapshot = TenantEnvironmentSnapshot::current() ?? new TenantEnvironmentSnapshot([
            '_id' => TenantEnvironmentSnapshot::ROOT_ID,
        ]);

        $snapshot->fill([
            'last_rebuild_reason' => $reason,
            'last_rebuild_context' => $context,
            'last_rebuild_started_at' => $startedAt,
        ]);
        $snapshot->save();

        try {
            $payload = $this->payloadFactory->buildSnapshotSource($tenant);
            $finishedAt = now();
            $snapshotVersion = $this->snapshotVersion($payload);

            $snapshot->fill([
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot_version' => $snapshotVersion,
                'snapshot' => $payload,
                'built_at' => $finishedAt,
                'last_rebuild_finished_at' => $finishedAt,
                'last_rebuild_failed_at' => null,
                'last_rebuild_error' => null,
            ]);
            $snapshot->save();

            Log::info('tenant_environment_snapshot_rebuilt', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'snapshot_version' => $snapshotVersion,
                'built_at' => $finishedAt->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $snapshot->fresh() ?? $snapshot;
        } catch (\Throwable $exception) {
            $failedAt = now();
            $snapshot->fill([
                'last_rebuild_finished_at' => $failedAt,
                'last_rebuild_failed_at' => $failedAt,
                'last_rebuild_error' => sprintf(
                    '%s: %s',
                    $exception::class,
                    $exception->getMessage(),
                ),
            ]);
            $snapshot->save();

            Log::error('tenant_environment_snapshot_rebuild_failed', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function summarize(TenantEnvironmentSnapshot $snapshot): array
    {
        return [
            'schema_version' => (int) ($snapshot->schema_version ?? 0),
            'snapshot_version' => (string) ($snapshot->snapshot_version ?? ''),
            'built_at' => $snapshot->built_at?->toIso8601String(),
            'last_rebuild_reason' => (string) ($snapshot->last_rebuild_reason ?? ''),
            'last_rebuild_failed_at' => $snapshot->last_rebuild_failed_at?->toIso8601String(),
            'last_rebuild_error' => (string) ($snapshot->last_rebuild_error ?? ''),
        ];
    }

    private function needsRepair(?TenantEnvironmentSnapshot $snapshot): bool
    {
        if (! $snapshot instanceof TenantEnvironmentSnapshot) {
            return true;
        }

        if ((int) ($snapshot->schema_version ?? 0) !== self::SCHEMA_VERSION) {
            return true;
        }

        return ! $this->hasUsableSnapshot($snapshot);
    }

    private function hasUsableSnapshot(TenantEnvironmentSnapshot $snapshot): bool
    {
        return is_array($snapshot->snapshot) && $snapshot->snapshot !== [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchRefreshForTenantId(string $tenantId, string $reason, array $context = []): void
    {
        if ($tenantId === '') {
            return;
        }

        $memoKey = sprintf('%s:%s', $tenantId, $reason);
        if (isset($this->dispatchMemo[$memoKey])) {
            return;
        }

        $this->dispatchMemo[$memoKey] = true;

        $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');
        $hadPreviousTenantId = Context::has($contextKey);
        $previousTenantId = $hadPreviousTenantId ? Context::get($contextKey) : null;

        Context::add($contextKey, $tenantId);

        try {
            RebuildTenantEnvironmentSnapshotJob::dispatch($reason, $context);
        } finally {
            if ($hadPreviousTenantId) {
                Context::add($contextKey, $previousTenantId);
            } else {
                Context::forget($contextKey);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateSnapshot(
        Tenant $tenant,
        ?TenantEnvironmentSnapshot $snapshot,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        return $this->payloadFactory->hydrateTenantPayload(
            tenant: $tenant,
            snapshot: $snapshot?->snapshot ?? [],
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function snapshotVersion(array $payload): string
    {
        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'payload' => $payload,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: Carbon::now()->toIso8601String(),
        );
    }
}
