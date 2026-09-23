<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Transactions;

use Belluga\Events\Exceptions\EventCommitOutcomeUnknownException;
use Belluga\Events\Exceptions\EventTransactionConflictException;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\Exception as MongoDriverException;
use MongoDB\Driver\Session;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Throwable;

class EventTransactionRunner
{
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
            $connection->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($this->hasErrorLabel($throwable, 'UnknownTransactionCommitResult')) {
                throw new EventCommitOutcomeUnknownException(
                    'The Event commit outcome is unknown.',
                    previous: $throwable,
                );
            }

            $this->abortIfActive($connection, $session);

            if ($this->hasErrorLabel($throwable, 'TransientTransactionError') || $this->isWriteConflict($throwable)) {
                throw new EventTransactionConflictException(
                    'Event mutation conflicted with a concurrent write.',
                    previous: $throwable,
                );
            }

            if ($this->isTransactionSupportError($throwable)) {
                throw new RuntimeException(
                    'Tenant MongoDB transaction support is required for events writes. Configure replica set / transaction-capable runtime.',
                    0,
                    $throwable,
                );
            }

            throw $throwable;
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
        if (! $throwable instanceof MongoDriverException) {
            return false;
        }

        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'transaction numbers are only allowed')
            || str_contains($message, 'transactions are not supported')
            || str_contains($message, 'replica set')
            || str_contains($message, 'mongos')
            || str_contains($message, 'starttransaction');
    }
}
