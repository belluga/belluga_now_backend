<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Transactions;

use Illuminate\Support\Facades\DB;
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

        try {
            return $connection->transaction(function () use ($callback, $connection) {
                $session = $connection->getSession();
                if ($session === null) {
                    throw new RuntimeException('Event transaction session is unavailable.');
                }

                return $callback(new EventTransactionContext(
                    $connection->getDatabase(),
                    $session,
                ));
            });
        } catch (Throwable $throwable) {
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
