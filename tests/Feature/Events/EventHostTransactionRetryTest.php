<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Exceptions\EventCommitOutcomeUnknownException;
use Belluga\Events\Exceptions\EventTransactionConflictException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Tests\TestCase;

final class EventHostTransactionRetryTest extends TestCase
{
    public function test_runner_delegates_transaction_choreography_to_the_canonical_connection_once(): void
    {
        $real = DB::connection('tenant');
        $this->assertInstanceOf(Connection::class, $real);
        $session = $real->getClient()?->startSession();
        $this->assertNotNull($session);

        /** @var Connection&\Mockery\MockInterface $connection */
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($real->getDatabase());
        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $bodyCalls = 0;

        try {
            $result = (new EventTransactionRunner)->run(function (EventTransactionContext $context) use (&$bodyCalls, $real, $session): string {
                $bodyCalls++;
                $this->assertSame($session, $context->session());
                $this->assertSame($real->getDatabase(), $context->database());

                return 'body result';
            });
        } finally {
            $session->endSession();
        }

        $this->assertSame('body result', $result);
        $this->assertSame(1, $bodyCalls);
    }

    public function test_transaction_context_commits_raw_operations_atomically(): void
    {
        $id = new ObjectId;

        $result = (new EventTransactionRunner)->run(function (EventTransactionContext $context) use ($id): string {
            $context->collection('events')->insertOne(
                ['_id' => $id, 'title' => 'Atomic event'],
                $context->rawOptions(),
            );

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertSame(
            'Atomic event',
            DB::connection('tenant')->getDatabase()->selectCollection('events')->findOne(['_id' => $id])?->title,
        );
    }

    public function test_callback_failure_rolls_back_and_propagates_the_original_error(): void
    {
        $id = new ObjectId;
        $failure = new RuntimeException('callback failed');

        try {
            (new EventTransactionRunner)->run(function (EventTransactionContext $context) use ($failure, $id): void {
                $context->collection('events')->insertOne(
                    ['_id' => $id, 'title' => 'Rolled back event'],
                    $context->rawOptions(),
                );

                throw $failure;
            });
            $this->fail('The callback error must escape the transaction boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertNull(
            DB::connection('tenant')->getDatabase()->selectCollection('events')->findOne(['_id' => $id]),
        );
    }

    public function test_callback_error_that_mentions_transaction_infrastructure_is_not_reclassified(): void
    {
        $failure = new RuntimeException('Domain input mentions replica set requirements.');

        try {
            (new EventTransactionRunner)->run(static fn (): never => throw $failure);
            $this->fail('A callback error must not be reclassified from message text.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function test_terminal_transient_failure_becomes_stable_conflict(): void
    {
        /** @var Connection&\Mockery\MockInterface $connection */
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andThrow(new LabeledEventTransactionFailure('transient', ['TransientTransactionError']));
        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);

        $this->expectException(EventTransactionConflictException::class);

        (new EventTransactionRunner)->run(static fn (): never => throw new RuntimeException('must not run'));
    }

    public function test_unlabelled_write_conflict_is_not_replayed(): void
    {
        $attempts = 0;
        $runner = new EventTransactionRunner;

        try {
            $runner->run(function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('write conflict', 112);
            });
            $this->fail('An unlabelled write conflict must fail without replay.');
        } catch (EventTransactionConflictException) {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_unknown_commit_result_maps_to_outcome_unknown_without_application_callback_replay(): void
    {
        $real = DB::connection('tenant');
        $this->assertInstanceOf(Connection::class, $real);
        $session = $real->getClient()?->startSession();
        $this->assertNotNull($session);

        /** @var Connection&\Mockery\MockInterface $connection */
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(function (callable $callback) use ($connection): never {
                $callback($connection);
                throw new LabeledEventTransactionFailure('unknown commit', ['UnknownTransactionCommitResult']);
            });
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($real->getDatabase());
        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $attempts = 0;

        try {
            (new EventTransactionRunner)->run(function () use (&$attempts): void {
                $attempts++;
            });
            $this->fail('A terminal unknown commit result must keep its distinct outcome.');
        } catch (EventCommitOutcomeUnknownException) {
            $this->assertSame(1, $attempts);
        } finally {
            $session->endSession();
        }
    }

    public function test_transaction_failures_render_the_stable_api_status_and_code_contracts(): void
    {
        $handler = app(ExceptionHandler::class);
        $request = Request::create('/api/v1/events', 'POST');

        $conflict = $handler->render($request, new EventTransactionConflictException('conflict'));
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(
            'event_revision_conflict',
            json_decode((string) $conflict->getContent(), true, flags: JSON_THROW_ON_ERROR)['code'] ?? null,
        );

        $unknown = $handler->render($request, new EventCommitOutcomeUnknownException('unknown'));
        $this->assertSame(503, $unknown->getStatusCode());
        $this->assertSame(
            'event_commit_outcome_unknown',
            json_decode((string) $unknown->getContent(), true, flags: JSON_THROW_ON_ERROR)['code'] ?? null,
        );
    }
}

final class LabeledEventTransactionFailure extends RuntimeException
{
    /** @param list<string> $labels */
    public function __construct(string $message, private readonly array $labels)
    {
        parent::__construct($message);
    }

    public function hasErrorLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }
}
