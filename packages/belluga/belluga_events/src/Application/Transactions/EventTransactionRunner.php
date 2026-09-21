<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Transactions;

use Belluga\Events\Exceptions\EventCommitOutcomeUnknownException;
use Belluga\Events\Exceptions\EventTransactionConflictException;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Session;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Throwable;

class EventTransactionRunner
{
    public const MAX_BODY_ATTEMPTS = 3;

    public const MAX_COMMIT_ATTEMPTS = 3;

    private const COMMAND_BUDGET_SECONDS = 2.0;

    /**
     * @template T
     *
     * @param  callable(EventTransactionContext): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException(
                'Tenant MongoDB transaction support is required for events writes, but the active driver has no transaction API.'
            );
        }

        $deadline = microtime(true) + self::COMMAND_BUDGET_SECONDS;
        for ($bodyAttempt = 1; $bodyAttempt <= self::MAX_BODY_ATTEMPTS; $bodyAttempt++) {
            if (microtime(true) >= $deadline) {
                throw new EventTransactionConflictException(
                    'Event mutation could not stabilize within the command budget.'
                );
            }

            $session = null;
            try {
                $connection->beginTransaction();
                $session = $connection->getSession();
                if (! $session instanceof Session) {
                    throw new RuntimeException('Event transaction session is unavailable.');
                }

                /** @var T $result */
                $result = $callback(new EventTransactionContext(
                    $connection->getDatabase(),
                    $session,
                ));
                if (microtime(true) >= $deadline) {
                    throw new EventTransactionConflictException(
                        'Event mutation could not stabilize within the command budget.'
                    );
                }
                $this->commit($connection, $deadline);

                return $result;
            } catch (EventCommitOutcomeUnknownException $exception) {
                throw $exception;
            } catch (Throwable $throwable) {
                $this->abortIfActive($connection, $session);

                if ($this->isTransactionSupportError($throwable)) {
                    throw new RuntimeException(
                        'Tenant MongoDB transaction support is required for events writes. Configure replica set / transaction-capable runtime.',
                        0,
                        $throwable,
                    );
                }

                if ($this->hasErrorLabel($throwable, 'TransientTransactionError')) {
                    if ($bodyAttempt >= self::MAX_BODY_ATTEMPTS || microtime(true) >= $deadline) {
                        throw new EventTransactionConflictException(
                            'Event mutation could not stabilize under concurrent writes.',
                            previous: $throwable,
                        );
                    }

                    usleep(random_int(10_000, 50_000));

                    continue;
                }

                if ($this->isWriteConflict($throwable)) {
                    throw new EventTransactionConflictException(
                        'Event mutation conflicted with a concurrent write.',
                        previous: $throwable,
                    );
                }

                throw $throwable;
            }
        }

        throw new EventTransactionConflictException('Event transaction attempts were exhausted.');
    }

    private function commit(Connection $connection, float $deadline): void
    {
        for ($attempt = 1; $attempt <= self::MAX_COMMIT_ATTEMPTS; $attempt++) {
            try {
                $connection->commit();

                return;
            } catch (Throwable $throwable) {
                if (! $this->hasErrorLabel($throwable, 'UnknownTransactionCommitResult')) {
                    throw $throwable;
                }
                if ($attempt >= self::MAX_COMMIT_ATTEMPTS || microtime(true) >= $deadline) {
                    throw new EventCommitOutcomeUnknownException(
                        'The Event commit outcome is unknown.',
                        previous: $throwable,
                    );
                }
            }
        }
    }

    private function abortIfActive(Connection $connection, ?Session $session): void
    {
        if (! $session instanceof Session || ! $session->isInTransaction()) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (Throwable) {
            // Preserve the original transaction failure.
        }
    }

    private function hasErrorLabel(Throwable $throwable, string $label): bool
    {
        return method_exists($throwable, 'hasErrorLabel')
            && $throwable->hasErrorLabel($label) === true;
    }

    private function isWriteConflict(Throwable $throwable): bool
    {
        return (int) $throwable->getCode() === 112
            || str_contains(strtolower($throwable->getMessage()), 'write conflict');
    }

    private function isTransactionSupportError(Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'transaction numbers are only allowed')
            || str_contains($message, 'transactions are not supported')
            || str_contains($message, 'replica set')
            || str_contains($message, 'mongos')
            || str_contains($message, 'starttransaction');
    }
}
