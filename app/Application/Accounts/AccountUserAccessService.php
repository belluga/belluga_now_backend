<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;

class AccountUserAccessService
{
    /**
     * @return array<int, string>
     */
    public function accountAccessIds(AccountUser $user): array
    {
        return collect($user->account_roles ?? [])
            ->pluck('account_id')
            ->map(static fn ($id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function permissions(AccountUser $user, ?Account $account = null): array
    {
        $account ??= Account::current();

        if (! $account) {
            throw new AuthenticationException;
        }

        $accountIds = $this->accountComparisonIds($account);

        return collect($user->account_roles)
            ->filter(static function (mixed $role) use ($accountIds): bool {
                $roleAccountId = self::roleAccountId($role);

                return $roleAccountId !== ''
                    && collect($accountIds)->contains(
                        static fn (string $accountId): bool => hash_equals($accountId, $roleAccountId)
                    );
            })
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }

    public function tokenAllows(AccountUser $user, string $ability): bool
    {
        $permissions = $this->permissions($user, Account::current());

        return $this->abilityListAllows($permissions, $ability);
    }

    /**
     * @param  array<int, string>  $allowedAbilities
     */
    public function abilityListAllows(array $allowedAbilities, string $ability): bool
    {
        $ability = trim($ability);
        if ($ability === '') {
            return false;
        }

        $allowedAbilities = collect($allowedAbilities)
            ->map(static fn (mixed $allowedAbility): string => trim((string) $allowedAbility))
            ->filter(static fn (string $allowedAbility): bool => $allowedAbility !== '')
            ->unique()
            ->values()
            ->all();

        if (in_array('*', $allowedAbilities, true) || in_array($ability, $allowedAbilities, true)) {
            return true;
        }

        $parts = explode(':', $ability, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;

        return $resource !== ''
            && $action !== ''
            && in_array("$resource:*", $allowedAbilities, true);
    }

    /**
     * @return array<int, string>
     */
    private function accountComparisonIds(Account $account): array
    {
        return collect([
            $account->id,
            $account->_id,
        ])
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private static function roleAccountId(mixed $role): string
    {
        $candidates = [
            is_array($role) ? ($role['account_id'] ?? null) : null,
            is_object($role) && method_exists($role, 'getAttribute') ? $role->getAttribute('account_id') : null,
            is_object($role) && isset($role->account_id) ? $role->account_id : null,
            data_get($role, 'account_id'),
        ];

        foreach ($candidates as $candidate) {
            $accountId = trim((string) $candidate);
            if ($accountId !== '') {
                return $accountId;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function syncCredential(
        AccountUser $user,
        string $provider,
        string $subject,
        ?string $secretHash = null,
        array $metadata = []
    ): array {
        $credentials = collect($user->credentials);

        $index = $credentials->search(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });

        if ($index !== false) {
            $credential = $credentials->get($index);

            if ($secretHash !== null) {
                $credential['secret_hash'] = $secretHash;
            }

            if ($metadata !== []) {
                $credential['metadata'] = $metadata;
            }

            $credentials->put($index, $credential);
            $user->credentials = $credentials->values()->all();
            $user->save();

            return $user->credentials[$index];
        }

        $credential = [
            '_id' => (string) new ObjectId,
            'provider' => $provider,
            'subject' => $subject,
            'secret_hash' => $secretHash,
            'metadata' => $metadata,
            'linked_at' => Carbon::now(),
            'last_used_at' => null,
        ];

        $credentials->push($credential);
        $user->credentials = $credentials->values()->all();
        $user->save();

        return $credential;
    }

    public function removeCredential(AccountUser $user, string $credentialId): bool
    {
        $credentials = collect($user->credentials);

        $filtered = $credentials->reject(static function (array $credential) use ($credentialId): bool {
            $currentId = $credential['_id'] ?? $credential['id'] ?? null;

            return $currentId === $credentialId;
        })->values();

        if ($filtered->count() === $credentials->count()) {
            return false;
        }

        $user->credentials = $filtered->all();
        $user->save();

        return true;
    }

    public function hasCredential(AccountUser $user, string $provider, string $subject): bool
    {
        return collect($user->credentials)->contains(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });
    }

    public function ensureEmail(AccountUser $user, string $email): void
    {
        $emails = $user->emails ?? [];

        if (! in_array($email, $emails, true)) {
            $emails[] = $email;
            $user->emails = array_values($emails);
            $user->save();
        }
    }
}
