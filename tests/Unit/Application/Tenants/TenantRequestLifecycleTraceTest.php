<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class TenantRequestLifecycleTraceTest extends TestCase
{
    public function test_begin_request_disarms_stale_subscribers_before_rearming_trace(): void
    {
        $trace = new TenantRequestLifecycleTrace;
        $request = Request::create('https://tenant.example.test/api/v1/environment', 'GET');
        $request->headers->set('X-Delphi-Tenant-Lifecycle-Trace', '1');

        $client = new class
        {
            /** @var list<object> */
            public array $addedSubscribers = [];

            /** @var list<object> */
            public array $removedSubscribers = [];

            public function addSubscriber(object $subscriber): void
            {
                $this->addedSubscribers[] = $subscriber;
            }

            public function removeSubscriber(object $subscriber): void
            {
                $this->removedSubscribers[] = $subscriber;
            }
        };

        $connection = new class($client)
        {
            public function __construct(
                private readonly object $client,
            ) {}

            public function getClient(): object
            {
                return $this->client;
            }
        };

        $originalDatabaseManager = DB::getFacadeRoot();

        DB::swap(new class($connection)
        {
            public function __construct(
                private readonly object $connection,
            ) {}

            public function getDefaultConnection(): string
            {
                return 'mongodb';
            }

            public function connection(?string $name = null): object
            {
                return $this->connection;
            }
        });

        try {
            $this->assertTrue(defined('LARAVEL_START'));
            $laravelStart = constant('LARAVEL_START');
            $this->assertIsNumeric($laravelStart);
            $laravelStart = (float) $laravelStart;

            $beforeBeginRequestMilliseconds = (microtime(true) - $laravelStart) * 1_000;
            $trace->beginRequest($request);
            $afterBeginRequestMilliseconds = (microtime(true) - $laravelStart) * 1_000;

            $response = new Response;
            $trace->appendResponseHeader($response);
            $payload = $this->decodeTraceResponseHeader($response, $trace);
            $startedEvent = collect($payload['events'])->firstWhere('stage', 'request.started');

            $this->assertIsArray($startedEvent);
            $this->assertArrayHasKey('laravel_start_to_tenancy_ms', $startedEvent);
            $this->assertTrue(
                is_int($startedEvent['laravel_start_to_tenancy_ms'])
                || is_float($startedEvent['laravel_start_to_tenancy_ms']),
            );
            $this->assertArrayNotHasKey('laravel_start', $startedEvent);
            $this->assertGreaterThanOrEqual(
                $beforeBeginRequestMilliseconds - 0.01,
                $startedEvent['laravel_start_to_tenancy_ms'],
            );
            $this->assertLessThanOrEqual(
                $afterBeginRequestMilliseconds + 0.01,
                $startedEvent['laravel_start_to_tenancy_ms'],
            );

            $trace->beginRequest($request);
        } finally {
            if ($originalDatabaseManager !== null) {
                DB::swap($originalDatabaseManager);
            }
        }

        $this->assertCount(2, $client->addedSubscribers);
        $this->assertCount(1, $client->removedSubscribers);
        $this->assertSame($client->addedSubscribers[0], $client->removedSubscribers[0]);
        $this->assertNotSame($client->addedSubscribers[0], $client->addedSubscribers[1]);
    }

    public function test_trace_is_not_enabled_in_production_even_with_opt_in_header(): void
    {
        $trace = new TenantRequestLifecycleTrace;
        $request = Request::create('https://tenant.example.test/api/v1/environment', 'GET');
        $request->headers->set('X-Delphi-Tenant-Lifecycle-Trace', '1');
        $response = new Response;
        $originalEnvironment = $this->app->environment();

        try {
            $this->app->detectEnvironment(static fn (): string => 'production');

            $trace->beginRequest($request);
            $trace->appendResponseHeader($response);
        } finally {
            $this->app->detectEnvironment(static fn (): string => $originalEnvironment);
        }

        $this->assertFalse($response->headers->has($trace->responseHeaderName()));
    }

    /**
     * @return array{events:list<array<string, mixed>>}
     */
    private function decodeTraceResponseHeader(Response $response, TenantRequestLifecycleTrace $trace): array
    {
        $encodedPayload = $response->headers->get($trace->responseHeaderName());
        $this->assertIsString($encodedPayload);

        $payload = base64_decode($encodedPayload, true);
        $this->assertIsString($payload);

        if ($response->headers->get($trace->responseHeaderName().'-Format') === 'base64-gzip-json') {
            $payload = gzdecode($payload);
            $this->assertIsString($payload);
        }

        /** @var array{events:list<array<string, mixed>>} $decodedPayload */
        $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return $decodedPayload;
    }
}
