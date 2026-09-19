<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Belluga\Events\Application\Events\EventAggregateWriteService;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Exceptions\EventCommitOutcomeUnknownException;
use Belluga\Events\Exceptions\EventTransactionConflictException;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Tests\TestCase;

final class EventHostTransactionRetryTest extends TestCase
{
    public function test_transient_body_failures_retry_the_complete_body_at_most_three_times(): void
    {
        $attempts = 0;
        $runner = new EventTransactionRunner;

        $result = $runner->run(function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                throw new LabeledEventTransactionFailure('transient', ['TransientTransactionError']);
            }

            return 'committed';
        });

        $this->assertSame('committed', $result);
        $this->assertSame(3, $attempts);
    }

    public function test_third_transient_body_failure_becomes_stable_conflict(): void
    {
        $attempts = 0;
        $runner = new EventTransactionRunner;

        try {
            $runner->run(function () use (&$attempts): void {
                $attempts++;
                throw new LabeledEventTransactionFailure('transient', ['TransientTransactionError']);
            });
            $this->fail('The third transient failure must exhaust the body budget.');
        } catch (EventTransactionConflictException) {
            $this->assertSame(3, $attempts);
        }
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

    public function test_aggregate_update_reloads_persisted_event_state_for_a_complete_body_retry(): void
    {
        $event = Event::query()->create([
            'title' => 'Original title',
            'slug' => 'event-retry-aggregate',
            'content' => '',
            'publication' => ['status' => 'draft', 'publish_at' => null],
            'is_active' => true,
        ]);
        $syncAttempts = 0;
        $occurrenceSync = Mockery::mock(EventOccurrenceSyncService::class);
        $occurrenceSync->shouldReceive('syncFromEvent')
            ->twice()
            ->andReturnUsing(function () use (&$syncAttempts): void {
                $syncAttempts++;
                if ($syncAttempts === 1) {
                    throw new LabeledEventTransactionFailure('transient', ['TransientTransactionError']);
                }
            });
        $this->app->instance(EventOccurrenceSyncService::class, $occurrenceSync);

        $updated = $this->app->make(EventAggregateWriteService::class)->update(
            $event,
            ['title' => 'Updated title'],
            [],
        );

        $this->assertSame(2, $syncAttempts);
        $this->assertSame('Updated title', $updated->fresh()?->title);
    }

    public function test_unknown_commit_result_retries_only_commit_and_exhausts_as_outcome_unknown(): void
    {
        $real = DB::connection('tenant');
        $this->assertInstanceOf(Connection::class, $real);
        /** @var Connection&\Mockery\MockInterface $connection */
        $connection = Mockery::mock($real)->makePartial();
        $connection->shouldReceive('commit')
            ->times(EventTransactionRunner::MAX_COMMIT_ATTEMPTS)
            ->andThrow(new LabeledEventTransactionFailure('unknown commit', ['UnknownTransactionCommitResult']));
        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $attempts = 0;
        $runner = new EventTransactionRunner;

        try {
            $runner->run(function () use (&$attempts): void {
                $attempts++;
            });
            $this->fail('Unknown commit exhaustion must have a distinct outcome.');
        } catch (EventCommitOutcomeUnknownException) {
            $this->assertSame(1, $attempts);
        } finally {
            if ($real instanceof Connection && $real->getSession()?->isInTransaction()) {
                $real->rollBack();
            }
        }
    }

    public function test_expired_command_budget_aborts_before_commit_starts(): void
    {
        $real = DB::connection('tenant');
        $this->assertInstanceOf(Connection::class, $real);
        /** @var Connection&\Mockery\MockInterface $connection */
        $connection = Mockery::mock($real)->makePartial();
        $connection->shouldNotReceive('commit');
        DB::shouldReceive('connection')->once()->with('tenant')->andReturn($connection);

        try {
            (new EventTransactionRunner)->run(static function (): void {
                usleep(2_010_000);
            });
            $this->fail('An expired command budget must abort before commit starts.');
        } catch (EventTransactionConflictException) {
            $this->assertTrue(true);
        } finally {
            if ($real->getSession()?->isInTransaction()) {
                $real->rollBack();
            }
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
