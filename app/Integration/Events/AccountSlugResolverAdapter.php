<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\Accounts\AccountQueryService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Events\Contracts\EventAccountResolverContract;
use MongoDB\BSON\ObjectId;

class AccountSlugResolverAdapter implements EventAccountResolverContract
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService
    ) {}

    public function resolveAccountIdBySlug(string $accountSlug): string
    {
        $account = $this->accountQueryService->findBySlugOrFail($accountSlug);

        return (string) $account->_id;
    }

    public function resolveAccessibleAccountUserIds(string $accountId): array
    {
        $normalizedAccountId = trim($accountId);
        if ($normalizedAccountId === '') {
            return [];
        }

        $account = $this->resolveAccountById($normalizedAccountId);
        if (! $account) {
            return [];
        }

        return AccountUser::query()
            ->get()
            ->filter(static fn (AccountUser $user): bool => $user->haveAccessTo($account))
            ->pluck('_id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    private function resolveAccountById(string $accountId): ?Account
    {
        if ($this->looksLikeObjectId($accountId)) {
            $account = Account::query()->where('_id', new ObjectId($accountId))->first();
            if ($account) {
                return $account;
            }
        }

        return Account::query()->where('_id', $accountId)->first();
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
