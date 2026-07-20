<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileCommandIndeterminateException;
use App\Application\AccountProfiles\AccountProfileTransactionRetryPolicy;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use Illuminate\Support\Facades\DB;
use Mockery;
use MongoDB\Database;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Tests\TestCase;

class AccountProfileTransactionRetryPolicyTest extends TestCase
{
    public function test_transient_transaction_body_retries_are_capped_at_three_attempts(): void
    {
        $policy = new AccountProfileTransactionRetryPolicy;
        $transient = new LabeledTransactionException(['TransientTransactionError']);

        $this->assertTrue($policy->shouldRetryBody($transient, 1));
        $this->assertTrue($policy->shouldRetryBody($transient, 2));
        $this->assertFalse($policy->shouldRetryBody($transient, 3));
    }

    public function test_unknown_commit_retries_do_not_reinvoke_the_transaction_body(): void
    {
        $policy = new AccountProfileTransactionRetryPolicy;
        $unknownCommit = new LabeledTransactionException(['UnknownTransactionCommitResult']);

        $this->assertTrue($policy->shouldRetryCommit($unknownCommit, 1));
        $this->assertTrue($policy->shouldRetryCommit($unknownCommit, 2));
        $this->assertFalse($policy->shouldRetryCommit($unknownCommit, 3));
        $this->assertFalse($policy->shouldRetryBody($unknownCommit, 1));
    }

    public function test_atlas_write_conflict_retries_the_transaction_body_with_the_same_cap(): void
    {
        $policy = new AccountProfileTransactionRetryPolicy;
        $writeConflict = new RuntimeException(
            'Write conflict during plan execution and yielding is disabled. Please retry your operation or multi-document transaction.',
            112,
        );

        $this->assertTrue($policy->shouldRetryBody($writeConflict, 1));
        $this->assertTrue($policy->shouldRetryBody($writeConflict, 2));
        $this->assertFalse($policy->shouldRetryBody($writeConflict, 3));
        $this->assertFalse($policy->shouldRetryCommit($writeConflict, 1));
    }

    public function test_runner_retries_a_write_conflict_raised_while_starting_a_transaction(): void
    {
        $realConnection = DB::connection('tenant');
        $realConnection->beginTransaction();
        $session = $realConnection->getSession();
        $realConnection->rollBack();

        $connection = Mockery::mock(Connection::class);
        $database = Mockery::mock(Database::class);
        $writeConflict = new RuntimeException('Write conflict during plan execution.', 112);
        $beginAttempts = 0;
        $bodyCalls = 0;

        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $connection->shouldReceive('beginTransaction')
            ->times(3)
            ->andReturnUsing(function () use (&$beginAttempts, $writeConflict): void {
                $beginAttempts++;
                if ($beginAttempts < 3) {
                    throw $writeConflict;
                }
            });
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($database);
        $connection->shouldReceive('commit')->once();

        $result = (new AccountProfileTransactionRunner(
            new AccountProfileTransactionRetryPolicy,
            static function (int $microseconds): void {},
        ))->run(
            function () use (&$bodyCalls): array {
                $bodyCalls++;

                return ['profile_id' => 'profile-after-write-conflict-retry'];
            },
        );

        $this->assertSame(3, $beginAttempts);
        $this->assertSame(1, $bodyCalls);
        $this->assertSame(['profile_id' => 'profile-after-write-conflict-retry'], $result);
    }

    public function test_runner_applies_bounded_write_conflict_backoff_before_reentering_a_transaction(): void
    {
        $realConnection = DB::connection('tenant');
        $realConnection->beginTransaction();
        $session = $realConnection->getSession();
        $realConnection->rollBack();

        $connection = Mockery::mock(Connection::class);
        $database = Mockery::mock(Database::class);
        $writeConflict = new RuntimeException('Write conflict during plan execution.', 112);
        $beginAttempts = 0;
        $delays = [];

        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $connection->shouldReceive('beginTransaction')
            ->times(3)
            ->andReturnUsing(function () use (&$beginAttempts, $writeConflict): void {
                $beginAttempts++;
                if ($beginAttempts < 3) {
                    throw $writeConflict;
                }
            });
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($database);
        $connection->shouldReceive('commit')->once();

        (new AccountProfileTransactionRunner(
            new AccountProfileTransactionRetryPolicy,
            function (int $microseconds) use (&$delays): void {
                $delays[] = $microseconds;
            },
        ))->run(static fn (): array => ['profile_id' => 'profile-after-backoff']);

        $this->assertSame([1_000_000, 2_000_000], $delays);
    }

    public function test_runner_normalizes_an_exhausted_write_conflict_to_a_concurrency_conflict(): void
    {
        $realConnection = DB::connection('tenant');
        $realConnection->beginTransaction();
        $session = $realConnection->getSession();
        $realConnection->rollBack();

        $connection = Mockery::mock(Connection::class);
        $database = Mockery::mock(Database::class);
        $writeConflict = new RuntimeException('Write conflict during plan execution.', 112);
        $bodyCalls = 0;

        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $connection->shouldReceive('beginTransaction')->times(3);
        $connection->shouldReceive('getSession')->times(3)->andReturn($session);
        $connection->shouldReceive('getDatabase')->times(3)->andReturn($database);

        try {
            (new AccountProfileTransactionRunner(
                new AccountProfileTransactionRetryPolicy,
                static function (int $microseconds): void {},
            ))->run(function () use (&$bodyCalls, $writeConflict): never {
                $bodyCalls++;

                throw $writeConflict;
            });
            $this->fail('An exhausted Atlas write conflict must become a normal concurrency conflict.');
        } catch (ConcurrencyConflictException $exception) {
            $this->assertSame($writeConflict, $exception->getPrevious());
        }

        $this->assertSame(3, $bodyCalls);
    }

    public function test_runner_reconciles_an_exhausted_unknown_commit_without_replaying_the_body(): void
    {
        $realConnection = DB::connection('tenant');
        $realConnection->beginTransaction();
        $session = $realConnection->getSession();
        $realConnection->rollBack();

        $connection = Mockery::mock(Connection::class);
        $database = Mockery::mock(Database::class);
        $unknownCommit = new LabeledTransactionException(['UnknownTransactionCommitResult']);

        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $connection->shouldReceive('beginTransaction')->once();
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($database);
        $connection->shouldReceive('commit')->times(3)->andThrow($unknownCommit);
        $bodyCalls = 0;
        $reconciliationCalls = 0;
        $result = (new AccountProfileTransactionRunner(new AccountProfileTransactionRetryPolicy))->run(
            function () use (&$bodyCalls): array {
                $bodyCalls++;

                return ['profile_id' => 'profile-from-command-body'];
            },
            function () use (&$reconciliationCalls): array {
                $reconciliationCalls++;

                return ['profile_id' => 'profile-from-command-receipt'];
            },
        );

        $this->assertSame(1, $bodyCalls);
        $this->assertSame(1, $reconciliationCalls);
        $this->assertSame(['profile_id' => 'profile-from-command-receipt'], $result);
    }

    public function test_runner_fails_closed_when_an_unknown_commit_has_no_receipt(): void
    {
        $realConnection = DB::connection('tenant');
        $realConnection->beginTransaction();
        $session = $realConnection->getSession();
        $realConnection->rollBack();

        $connection = Mockery::mock(Connection::class);
        $database = Mockery::mock(Database::class);
        $unknownCommit = new LabeledTransactionException(['UnknownTransactionCommitResult']);

        DB::shouldReceive('connection')
            ->once()
            ->with('tenant')
            ->andReturn($connection);
        $connection->shouldReceive('beginTransaction')->once();
        $connection->shouldReceive('getSession')->once()->andReturn($session);
        $connection->shouldReceive('getDatabase')->once()->andReturn($database);
        $connection->shouldReceive('commit')->times(3)->andThrow($unknownCommit);
        $bodyCalls = 0;
        $reconciliationCalls = 0;

        try {
            (new AccountProfileTransactionRunner(new AccountProfileTransactionRetryPolicy))->run(
                function () use (&$bodyCalls): array {
                    $bodyCalls++;

                    return ['profile_id' => 'profile-from-command-body'];
                },
                function () use (&$reconciliationCalls): ?array {
                    $reconciliationCalls++;

                    return null;
                },
            );
            $this->fail('The runner must not acknowledge an unknown commit without a command receipt.');
        } catch (AccountProfileCommandIndeterminateException $exception) {
            $this->assertSame($unknownCommit, $exception->getPrevious());
        }

        $this->assertSame(1, $bodyCalls);
        $this->assertSame(1, $reconciliationCalls);
    }
}

final class LabeledTransactionException extends RuntimeException
{
    /** @param list<string> $labels */
    public function __construct(private readonly array $labels)
    {
        parent::__construct('labeled transaction failure');
    }

    public function hasErrorLabel(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }
}
