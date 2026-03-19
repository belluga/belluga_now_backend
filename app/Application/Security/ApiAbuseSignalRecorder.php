<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Models\Landlord\ApiAbuseSignal;
use App\Models\Landlord\ApiAbuseSignalAggregate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiAbuseSignalRecorder
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordViolation(array $payload): void
    {
        if (! (bool) config('api_security.abuse_signals.enabled', true)) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $rawRetentionHours = max(1, (int) config('api_security.abuse_signals.raw_retention_hours', 72));
        $aggregateRetentionDays = max(1, (int) config('api_security.abuse_signals.aggregate_retention_days', 30));

        $tenantReference = $this->normalizeNullableString($payload['tenant_reference'] ?? null);
        $identity = (string) ($payload['identity'] ?? 'anon');
        $principalHash = $this->hashIdentifier($identity);

        $rawSignal = [
            'kind' => 'violation',
            'code' => (string) ($payload['code'] ?? 'unknown'),
            'action' => (string) ($payload['action'] ?? 'none'),
            'level' => (string) ($payload['level'] ?? 'L2'),
            'level_source' => (string) ($payload['level_source'] ?? 'system_default'),
            'tenant_reference' => $tenantReference,
            'principal_hash' => $principalHash,
            'method' => strtoupper((string) ($payload['method'] ?? 'GET')),
            'path' => (string) ($payload['path'] ?? ''),
            'correlation_id' => $this->normalizeNullableString($payload['correlation_id'] ?? null),
            'cf_ray_id' => $this->normalizeNullableString($payload['cf_ray_id'] ?? null),
            'observe_mode' => (bool) ($payload['observe_mode'] ?? false),
            'blocked' => (bool) ($payload['blocked'] ?? false),
            'retry_after' => Arr::get($payload, 'retry_after'),
            'state_count' => (int) ($payload['state_count'] ?? 0),
            'metadata' => $this->sanitizeMetadata((array) ($payload['metadata'] ?? [])),
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now->addHours($rawRetentionHours),
        ];

        ApiAbuseSignal::query()->create($rawSignal);

        $bucketAt = $now->startOfHour();
        $aggregateMatch = [
            'bucket_at' => $bucketAt,
            'code' => $rawSignal['code'],
            'action' => $rawSignal['action'],
            'level' => $rawSignal['level'],
            'tenant_reference' => $tenantReference,
            'method' => $rawSignal['method'],
            'path' => $rawSignal['path'],
            'observe_mode' => $rawSignal['observe_mode'],
        ];

        $aggregate = ApiAbuseSignalAggregate::query()
            ->where('bucket_at', $bucketAt)
            ->where('code', $rawSignal['code'])
            ->where('action', $rawSignal['action'])
            ->where('level', $rawSignal['level'])
            ->where('tenant_reference', $tenantReference)
            ->where('method', $rawSignal['method'])
            ->where('path', $rawSignal['path'])
            ->where('observe_mode', $rawSignal['observe_mode'])
            ->first();

        if ($aggregate === null) {
            ApiAbuseSignalAggregate::query()->create([
                ...$aggregateMatch,
                'count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $now->addDays($aggregateRetentionDays),
            ]);

            return;
        }

        try {
            $aggregate->increment('count');
        } catch (Throwable $exception) {
            Log::warning('Failed to increment API abuse aggregate counter.', [
                'code' => 'abuse_signal_aggregate_increment_failed',
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'aggregate_match' => $aggregateMatch,
            ]);
        }

        $aggregate->setAttribute('updated_at', $now);
        $aggregate->setAttribute('expires_at', $now->addDays($aggregateRetentionDays));
        $aggregate->save();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function summarize(int $hours, array $filters = []): array
    {
        $hours = max(1, min($hours, 24 * 30));
        $since = CarbonImmutable::now('UTC')->subHours($hours)->startOfHour();

        $query = ApiAbuseSignalAggregate::query()->where('bucket_at', '>=', $since);

        $tenantReference = $this->normalizeNullableString($filters['tenant_reference'] ?? null);
        if ($tenantReference !== null) {
            $query->where('tenant_reference', $tenantReference);
        }

        $level = $this->normalizeNullableString($filters['level'] ?? null);
        if ($level !== null) {
            $query->where('level', strtoupper($level));
        }

        $code = $this->normalizeNullableString($filters['code'] ?? null);
        if ($code !== null) {
            $query->where('code', $code);
        }

        $rows = $query->orderBy('bucket_at', 'desc')->get()->all();

        $total = 0;
        $groupedByCode = [];
        foreach ($rows as $row) {
            $count = (int) ($row->getAttribute('count') ?? 0);
            $total += $count;
            $codeKey = (string) ($row->getAttribute('code') ?? 'unknown');
            $groupedByCode[$codeKey] = ($groupedByCode[$codeKey] ?? 0) + $count;
        }
        arsort($groupedByCode);

        return [
            'since' => $since->toIso8601String(),
            'hours' => $hours,
            'total' => $total,
            'grouped_by_code' => $groupedByCode,
            'rows' => array_map(static function ($row): array {
                return [
                    'bucket_at' => (string) $row->getAttribute('bucket_at'),
                    'code' => (string) $row->getAttribute('code'),
                    'action' => (string) $row->getAttribute('action'),
                    'level' => (string) $row->getAttribute('level'),
                    'tenant_reference' => $row->getAttribute('tenant_reference'),
                    'method' => (string) $row->getAttribute('method'),
                    'path' => (string) $row->getAttribute('path'),
                    'observe_mode' => (bool) $row->getAttribute('observe_mode'),
                    'count' => (int) $row->getAttribute('count'),
                ];
            }, $rows),
        ];
    }

    /**
     * @return array{raw_deleted:int,aggregate_deleted:int}
     */
    public function pruneExpired(): array
    {
        $now = CarbonImmutable::now('UTC');

        $rawDeleted = ApiAbuseSignal::query()
            ->where('expires_at', '<=', $now)
            ->delete();

        $aggregateDeleted = ApiAbuseSignalAggregate::query()
            ->where('expires_at', '<=', $now)
            ->delete();

        return [
            'raw_deleted' => (int) $rawDeleted,
            'aggregate_deleted' => (int) $aggregateDeleted,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function listSignals(array $filters, int $limit): array
    {
        $kind = strtolower((string) ($filters['kind'] ?? 'aggregate'));
        $limit = max(1, min($limit, 250));

        if ($kind === 'raw') {
            $query = ApiAbuseSignal::query()->orderBy('created_at', 'desc');
            $level = $this->normalizeNullableString($filters['level'] ?? null);
            if ($level !== null) {
                $query->where('level', strtoupper($level));
            }

            $code = $this->normalizeNullableString($filters['code'] ?? null);
            if ($code !== null) {
                $query->where('code', $code);
            }

            $tenantReference = $this->normalizeNullableString($filters['tenant_reference'] ?? null);
            if ($tenantReference !== null) {
                $query->where('tenant_reference', $tenantReference);
            }

            return array_map(static function ($row): array {
                return [
                    'kind' => 'raw',
                    'created_at' => (string) $row->getAttribute('created_at'),
                    'code' => (string) $row->getAttribute('code'),
                    'action' => (string) $row->getAttribute('action'),
                    'level' => (string) $row->getAttribute('level'),
                    'level_source' => (string) $row->getAttribute('level_source'),
                    'tenant_reference' => $row->getAttribute('tenant_reference'),
                    'method' => (string) $row->getAttribute('method'),
                    'path' => (string) $row->getAttribute('path'),
                    'observe_mode' => (bool) $row->getAttribute('observe_mode'),
                    'blocked' => (bool) $row->getAttribute('blocked'),
                    'retry_after' => $row->getAttribute('retry_after'),
                    'state_count' => (int) $row->getAttribute('state_count'),
                    'principal_hash' => (string) $row->getAttribute('principal_hash'),
                    'correlation_id' => $row->getAttribute('correlation_id'),
                    'cf_ray_id' => $row->getAttribute('cf_ray_id'),
                    'metadata' => (array) ($row->getAttribute('metadata') ?? []),
                ];
            }, $query->limit($limit)->get()->all());
        }

        $summary = $this->summarize((int) ($filters['hours'] ?? 24), $filters);

        return array_slice((array) ($summary['rows'] ?? []), 0, $limit);
    }

    private function hashIdentifier(string $value): string
    {
        $salt = (string) config('api_security.abuse_signals.hash_salt', 'api-security');

        return hash_hmac('sha256', $value, $salt);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $maxBytes = max(256, (int) config('api_security.abuse_signals.max_metadata_bytes', 4096));
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return [];
        }

        if (strlen($json) <= $maxBytes) {
            return $metadata;
        }

        return [
            'truncated' => true,
            'bytes' => strlen($json),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
