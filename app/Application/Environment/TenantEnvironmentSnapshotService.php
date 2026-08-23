<?php

declare(strict_types=1);

namespace App\Application\Environment;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Jobs\Environment\RebuildTenantEnvironmentSnapshotJob;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class TenantEnvironmentSnapshotService
{
    public const SCHEMA_VERSION = 3;

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
        app(TenantRequestLifecycleTrace::class)->record('environment.snapshot.lookup.start');
        $snapshotDocument = $this->currentSnapshotDocument();
        app(TenantRequestLifecycleTrace::class)->record('environment.snapshot.lookup.loaded', [
            'snapshot_present' => is_array($snapshotDocument),
            'snapshot_schema_version' => $this->snapshotSchemaVersion($snapshotDocument),
        ]);

        if ($this->needsRepairDocument($snapshotDocument)) {
            $reason = $snapshotDocument === null ? 'missing_snapshot' : 'version_drift';
            Log::warning('tenant_environment_snapshot_repair_requested', [
                'tenant_id' => (string) $tenant->getKey(),
                'tenant_slug' => (string) $tenant->slug,
                'reason' => $reason,
                'schema_version' => $this->snapshotSchemaVersion($snapshotDocument),
                'expected_schema_version' => self::SCHEMA_VERSION,
            ]);

            try {
                $snapshot = $this->repair($tenant, $reason, [
                    'trigger' => 'read_path',
                    'request_host' => $requestHost,
                ]);

                return $this->hydrateSnapshot($tenant, $snapshot, $requestRoot, $requestHost);
            } catch (\Throwable $exception) {
                if ($this->hasUsableSnapshotDocument($snapshotDocument)) {
                    Log::warning('tenant_environment_snapshot_repair_failed_serving_last_valid', [
                        'tenant_id' => (string) $tenant->getKey(),
                        'tenant_slug' => (string) $tenant->slug,
                        'reason' => $reason,
                        'error' => $exception->getMessage(),
                        'snapshot_version' => (string) ($snapshotDocument['snapshot_version'] ?? ''),
                        'built_at' => $this->snapshotBuiltAtIso($snapshotDocument),
                    ]);

                    return $this->hydrateSnapshotPayload(
                        tenant: $tenant,
                        snapshotPayload: $this->snapshotPayload($snapshotDocument),
                        requestRoot: $requestRoot,
                        requestHost: $requestHost,
                    );
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

        return $this->hydrateSnapshotPayload(
            tenant: $tenant,
            snapshotPayload: $this->snapshotPayload($snapshotDocument),
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    public function dispatchRefreshForCurrentTenant(string $reason, array $context = []): void
    {
        $tenant = Tenant::current();
        if (! $tenant || ! $tenant->isCurrent()) {
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

    private function needsRepairDocument(?array $snapshotDocument): bool
    {
        if ($snapshotDocument === null) {
            return true;
        }

        if ($this->snapshotSchemaVersion($snapshotDocument) !== self::SCHEMA_VERSION) {
            return true;
        }

        return ! $this->hasUsableSnapshotDocument($snapshotDocument);
    }

    private function hasUsableSnapshotDocument(?array $snapshotDocument): bool
    {
        return $this->hasUsableSnapshotPayload($this->snapshotPayload($snapshotDocument));
    }

    private function hasUsableSnapshotPayload(mixed $rawSnapshot): bool
    {
        if (is_array($rawSnapshot)) {
            return $rawSnapshot !== [];
        }

        if ($rawSnapshot instanceof \Countable) {
            return count($rawSnapshot) > 0;
        }

        if ($rawSnapshot instanceof \Traversable) {
            foreach ($rawSnapshot as $_) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchRefreshForTenantId(string $tenantId, string $reason, array $context = []): void
    {
        if ($tenantId === '' || ! $this->landlordSnapshotContextAvailable()) {
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
        $previousCurrentTenant = Tenant::current();
        $currentTenantId = trim((string) ($previousCurrentTenant?->getKey() ?? ''));
        $restoreTenantId = $currentTenantId !== ''
            ? $currentTenantId
            : trim((string) ($previousTenantId ?? ''));

        $dispatchTenant = $previousCurrentTenant instanceof Tenant
            && (string) $previousCurrentTenant->getKey() === $tenantId
            ? $previousCurrentTenant
            : Tenant::query()->find($tenantId);

        if ($dispatchTenant instanceof Tenant) {
            $dispatchTenant->makeCurrent();
        }

        Context::add($contextKey, $tenantId);

        try {
            RebuildTenantEnvironmentSnapshotJob::dispatch($tenantId, $reason, $context);
        } finally {
            $restoreTenant = null;
            if ($restoreTenantId !== '') {
                $restoreTenant = $previousCurrentTenant instanceof Tenant
                    && (string) $previousCurrentTenant->getKey() === $restoreTenantId
                    ? $previousCurrentTenant
                    : Tenant::query()->find($restoreTenantId);
            }

            if ($restoreTenant instanceof Tenant) {
                $restoreTenant->makeCurrent();
            } else {
                Tenant::forgetCurrent();

                if ($hadPreviousTenantId) {
                    Context::add($contextKey, $previousTenantId);
                } else {
                    Context::forget($contextKey);
                }
            }
        }
    }

    private function landlordSnapshotContextAvailable(): bool
    {
        return Landlord::query()->exists();
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
        return $this->hydrateSnapshotPayload(
            tenant: $tenant,
            snapshotPayload: $snapshot instanceof TenantEnvironmentSnapshot
                ? $this->rawSnapshotPayload($snapshot)
                : [],
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrateSnapshotPayload(
        Tenant $tenant,
        mixed $snapshotPayload,
        ?string $requestRoot,
        ?string $requestHost,
    ): array {
        app(TenantRequestLifecycleTrace::class)->record('environment.snapshot.hydrate.start');

        $payload = $this->payloadFactory->hydrateTenantPayload(
            tenant: $tenant,
            snapshot: $snapshotPayload,
            requestRoot: $requestRoot,
            requestHost: $requestHost,
        );

        app(TenantRequestLifecycleTrace::class)->record('environment.snapshot.hydrate.complete');

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentSnapshotDocument(): ?array
    {
        $document = TenantEnvironmentSnapshot::query()->getQuery()->raw(
            fn ($collection) => $collection->findOne(['_id' => TenantEnvironmentSnapshot::ROOT_ID])
        );

        return $this->normalizeSnapshotDocument($document);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeSnapshotDocument(mixed $document): ?array
    {
        $document = $this->normalizeMongoValue($document);

        return is_array($document) ? $document : null;
    }

    private function snapshotSchemaVersion(?array $snapshotDocument): int
    {
        return (int) ($snapshotDocument['schema_version'] ?? 0);
    }

    private function snapshotPayload(?array $snapshotDocument): mixed
    {
        return $snapshotDocument['snapshot'] ?? [];
    }

    private function snapshotBuiltAtIso(?array $snapshotDocument): ?string
    {
        $builtAt = $snapshotDocument['built_at'] ?? null;

        if ($builtAt instanceof \MongoDB\BSON\UTCDateTime) {
            return Carbon::instance($builtAt->toDateTime())->toIso8601String();
        }

        if ($builtAt instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($builtAt))->toIso8601String();
        }

        return null;
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeMongoValue($item);
            }

            return $normalized;
        }

        if (is_object($value) && ! $value instanceof \DateTimeInterface && ! $value instanceof \MongoDB\BSON\UTCDateTime) {
            return $this->normalizeMongoValue((array) $value);
        }

        return $value;
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

    private function rawSnapshotPayload(TenantEnvironmentSnapshot $snapshot): mixed
    {
        if (method_exists($snapshot, 'getRawOriginal')) {
            return $snapshot->getRawOriginal('snapshot');
        }

        return $snapshot->getAttributes()['snapshot'] ?? null;
    }
}
