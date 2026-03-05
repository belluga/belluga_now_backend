<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Operations;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;
use Belluga\Events\Contracts\EventAsyncQueueMetricsProviderContract;
use Illuminate\Support\Facades\DB;

class QueueEventAsyncMetricsProvider implements EventAsyncQueueMetricsProviderContract
{
    public function __construct(
        private readonly EventAsyncJobSignaturesContract $jobSignatures
    ) {
    }

    public function pendingAgesInSeconds(): array
    {
        $defaultQueueConnection = (string) config('queue.default', 'sync');
        $queueConnectionConfig = config("queue.connections.{$defaultQueueConnection}");

        if (! is_array($queueConnectionConfig)) {
            return [];
        }

        $databaseConnection = $this->resolveDatabaseConnectionName($defaultQueueConnection, $queueConnectionConfig);
        $queueTable = $this->resolveQueueTableOrCollection($queueConnectionConfig);
        $queueName = (string) ($queueConnectionConfig['queue'] ?? 'default');

        if ($databaseConnection === null || $queueTable === null) {
            return [];
        }

        $now = time();

        $query = DB::connection($databaseConnection)
            ->table($queueTable)
            ->select(['available_at', 'payload'])
            ->where('queue', '=', $queueName)
            ->where('available_at', '<=', $now)
            ->where(static function ($builder): void {
                $builder->whereNull('reserved_at')
                    ->orWhere('reserved_at', '=', 0);
            })
            ->orderBy('available_at');

        $ages = [];

        foreach ($query->cursor() as $queuedJob) {
            $payload = (string) ($queuedJob->payload ?? '');
            if (! $this->isEventsAsyncJobPayload($payload)) {
                continue;
            }

            $availableAt = (int) ($queuedJob->available_at ?? 0);
            if ($availableAt <= 0) {
                continue;
            }

            $ages[] = max(0, $now - $availableAt);
        }

        return $ages;
    }

    /**
     * @param array<string, mixed> $queueConnectionConfig
     */
    private function resolveDatabaseConnectionName(string $defaultQueueConnection, array $queueConnectionConfig): ?string
    {
        $driver = (string) ($queueConnectionConfig['driver'] ?? '');
        $fallback = (string) config('database.default', 'landlord');

        if ($driver === 'database') {
            $connection = $queueConnectionConfig['connection'] ?? null;
            if (is_string($connection) && $connection !== '') {
                return $connection;
            }

            return $fallback;
        }

        if ($driver === 'mongodb') {
            $connection = $queueConnectionConfig['connection'] ?? null;
            if (is_string($connection) && $connection !== '') {
                return $connection;
            }

            return $fallback;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $queueConnectionConfig
     */
    private function resolveQueueTableOrCollection(array $queueConnectionConfig): ?string
    {
        $driver = (string) ($queueConnectionConfig['driver'] ?? '');

        if ($driver === 'database') {
            $table = $queueConnectionConfig['table'] ?? null;

            return is_string($table) && $table !== '' ? $table : 'jobs';
        }

        if ($driver === 'mongodb') {
            $collection = $queueConnectionConfig['collection'] ?? null;

            return is_string($collection) && $collection !== '' ? $collection : 'jobs';
        }

        return null;
    }

    private function isEventsAsyncJobPayload(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        foreach ($this->jobSignatures->signatures() as $signature) {
            if ($signature === '') {
                continue;
            }
            if (
                str_contains($payload, $signature)
                || str_contains($payload, str_replace('\\', '\\\\', $signature))
            ) {
                return true;
            }
        }

        return false;
    }
}
