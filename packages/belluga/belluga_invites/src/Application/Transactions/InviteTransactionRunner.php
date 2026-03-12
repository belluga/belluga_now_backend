<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Transactions;

use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class InviteTransactionRunner
{
    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $connection = DB::connection('tenant');

        if (! method_exists($connection, 'transaction')) {
            throw new InviteDomainException(
                errorCode: 'transaction_unavailable',
                httpStatus: 500,
                message: 'Tenant MongoDB transaction support is required for invite critical flows.'
            );
        }

        try {
            return $connection->transaction(static fn () => $callback());
        } catch (Throwable $throwable) {
            if ($this->isTransactionSupportError($throwable)) {
                throw new InviteDomainException(
                    errorCode: 'transaction_unavailable',
                    httpStatus: 500,
                    message: 'Configure replica set / transaction-capable runtime for invite critical flows.',
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
