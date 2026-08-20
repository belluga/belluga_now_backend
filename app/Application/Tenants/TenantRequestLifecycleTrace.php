<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use Symfony\Component\HttpFoundation\Response;

final class TenantRequestLifecycleTrace
{
    private const REQUEST_HEADER_NAME = 'X-Delphi-Tenant-Lifecycle-Trace';

    private const RESPONSE_HEADER_NAME = 'X-Delphi-Tenant-Lifecycle-Trace-Data';

    private bool $enabled = false;

    private int $startedAtNanoseconds = 0;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var array<string, TenantRequestLifecycleMongoCommandSubscriber> */
    private array $mongoSubscribers = [];

    /** @var array<string, bool> */
    private array $firstMongoCommandRecorded = [];

    /** @var array{events:list<array<string, mixed>>}|null */
    private ?array $lastCompletedTrace = null;

    public function beginRequest(Request $request): void
    {
        $this->disarmAllConnectionTraces();
        $this->resetActiveTrace();
        $this->lastCompletedTrace = null;

        if (! $this->shouldEnableFor($request)) {
            return;
        }

        $this->enabled = true;
        $this->startedAtNanoseconds = hrtime(true);
        $traceStartedAt = microtime(true);

        $this->record('request.started', [
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'host_hash' => $this->redactIdentifier($request->getHost()),
            'pid' => getmypid(),
            'laravel_start_to_tenancy_ms' => $this->laravelStartToTenancyMilliseconds($traceStartedAt),
        ]);

        $this->armConnectionTrace(
            (string) config('multitenancy.landlord_database_connection_name', 'landlord')
        );
    }

    public function appendResponseHeader(Response $response): void
    {
        if (! $this->enabled) {
            return;
        }

        $json = json_encode($this->currentTracePayload(), JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || $json === '') {
            return;
        }

        $encodedPayload = gzencode($json, 6);
        if (is_string($encodedPayload) && $encodedPayload !== '') {
            $response->headers->set(self::RESPONSE_HEADER_NAME, base64_encode($encodedPayload));
            $response->headers->set(self::RESPONSE_HEADER_NAME.'-Format', 'base64-gzip-json');

            return;
        }

        $response->headers->set(self::RESPONSE_HEADER_NAME, base64_encode($json));
        $response->headers->set(self::RESPONSE_HEADER_NAME.'-Format', 'base64-json');
    }

    public function finishRequest(): void
    {
        if (! $this->enabled) {
            $this->disarmAllConnectionTraces();
            $this->resetActiveTrace();

            return;
        }

        $this->lastCompletedTrace = $this->currentTracePayload();

        $this->disarmAllConnectionTraces();
        $this->resetActiveTrace();
    }

    public function record(string $stage, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->events[] = array_filter([
            'stage' => $stage,
            't_ms' => $this->elapsedMilliseconds(),
            ...$this->runtimeSnapshot(),
            ...$context,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotRuntime(): array
    {
        return $this->runtimeSnapshot();
    }

    public function armConnectionTrace(string $connectionName): void
    {
        if (! $this->enabled || $connectionName === '' || isset($this->mongoSubscribers[$connectionName])) {
            return;
        }

        $connection = DB::connection($connectionName);
        if (! method_exists($connection, 'getClient')) {
            return;
        }

        $client = $connection->getClient();
        if (! is_object($client) || ! method_exists($client, 'addSubscriber')) {
            return;
        }

        $subscriber = new TenantRequestLifecycleMongoCommandSubscriber($connectionName, $this);
        $client->addSubscriber($subscriber);
        $this->mongoSubscribers[$connectionName] = $subscriber;

        $this->record('mongo.trace.armed', [
            'connection' => $connectionName,
        ]);
    }

    public function disarmConnectionTrace(string $connectionName): void
    {
        $subscriber = $this->mongoSubscribers[$connectionName] ?? null;
        if ($subscriber === null) {
            return;
        }

        unset($this->mongoSubscribers[$connectionName]);

        $connection = DB::connection($connectionName);
        if (! method_exists($connection, 'getClient')) {
            return;
        }

        $client = $connection->getClient();
        if (! is_object($client) || ! method_exists($client, 'removeSubscriber')) {
            return;
        }

        $client->removeSubscriber($subscriber);
    }

    public function recordFirstMongoCommand(string $connectionName, CommandStartedEvent $event): void
    {
        if (! $this->enabled || isset($this->firstMongoCommandRecorded[$connectionName])) {
            return;
        }

        $this->firstMongoCommandRecorded[$connectionName] = true;

        $command = get_object_vars($event->getCommand());
        $commandName = $event->getCommandName();

        $this->record("mongo.first.{$connectionName}", [
            'connection' => $connectionName,
            'command' => $commandName,
            'collection' => $this->resolveCommandCollection($commandName, $command),
        ]);
    }

    public function recordMongoCommand(string $connectionName, CommandStartedEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $command = get_object_vars($event->getCommand());
        $commandName = $event->getCommandName();

        $this->record("mongo.command.{$connectionName}", array_filter([
            'connection' => $connectionName,
            'command' => $commandName,
            'collection' => $this->resolveCommandCollection($commandName, $command),
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function redactIdentifier(?string $value): ?string
    {
        $normalized = is_string($value) ? trim($value) : '';
        if ($normalized === '') {
            return null;
        }

        return substr(hash('sha256', $normalized), 0, 12);
    }

    public function tenantFingerprint(mixed $tenant): ?string
    {
        if (! is_object($tenant) || ! method_exists($tenant, 'getKey')) {
            return null;
        }

        $key = $tenant->getKey();

        return $this->redactIdentifier(is_scalar($key) ? (string) $key : null);
    }

    /**
     * @return array{events:list<array<string, mixed>>}|null
     */
    public function lastCompletedTrace(): ?array
    {
        return $this->lastCompletedTrace;
    }

    public function responseHeaderName(): string
    {
        return self::RESPONSE_HEADER_NAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeSnapshot(): array
    {
        return [
            'default_connection' => DB::getDefaultConnection(),
            'tenant_current' => $this->tenantFingerprint(Tenant::current()),
        ];
    }

    private function shouldEnableFor(Request $request): bool
    {
        if (! app()->environment(['local', 'testing'])) {
            return false;
        }

        return $request->headers->get(self::REQUEST_HEADER_NAME) === '1';
    }

    private function elapsedMilliseconds(): float
    {
        if ($this->startedAtNanoseconds === 0) {
            return 0.0;
        }

        return round((hrtime(true) - $this->startedAtNanoseconds) / 1_000_000, 3);
    }

    private function laravelStartToTenancyMilliseconds(float $traceStartedAt): ?float
    {
        if (! defined('LARAVEL_START')) {
            return null;
        }

        $laravelStart = constant('LARAVEL_START');
        if (! is_numeric($laravelStart)) {
            return null;
        }

        return round(max(0.0, ($traceStartedAt - (float) $laravelStart) * 1_000), 3);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function resolveCommandCollection(string $commandName, array $command): ?string
    {
        $collection = $command[$commandName] ?? null;

        return is_string($collection) && $collection !== '' ? $collection : null;
    }

    /**
     * @return array{events:list<array<string, mixed>>}
     */
    private function currentTracePayload(): array
    {
        return [
            'events' => $this->events,
        ];
    }

    private function disarmAllConnectionTraces(): void
    {
        foreach (array_keys($this->mongoSubscribers) as $connectionName) {
            $this->disarmConnectionTrace($connectionName);
        }
    }

    private function resetActiveTrace(): void
    {
        $this->enabled = false;
        $this->startedAtNanoseconds = 0;
        $this->events = [];
        $this->mongoSubscribers = [];
        $this->firstMongoCommandRecorded = [];
    }
}
