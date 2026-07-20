<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\AccountUser;
use MongoDB\BSON\ObjectId;

final class AccountProfileDeletionBarrier
{
    public function touch(AccountProfileTransactionContext $context, string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new ConcurrencyConflictException('Account Profile deletion barrier requires a tenant user.');
        }

        try {
            $objectId = new ObjectId($userId);
        } catch (\Throwable) {
            throw new ConcurrencyConflictException('Account Profile deletion barrier tenant user is invalid.');
        }

        $result = $context->collection((new AccountUser)->getTable())->updateOne(
            ['_id' => $objectId, 'deleted_at' => null],
            ['$inc' => ['account_profile_deletion_barrier_revision' => 1]],
            $context->rawOptions(),
        );
        if ($result->getMatchedCount() !== 1) {
            throw new ConcurrencyConflictException('Account Profile deletion barrier tenant user is unavailable.');
        }
    }
}
