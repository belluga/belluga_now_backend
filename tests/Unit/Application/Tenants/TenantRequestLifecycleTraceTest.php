<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $trace->beginRequest($request);
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
}
