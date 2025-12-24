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
            ->map(static fn ($id): string => (string) $id)
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
            throw new AuthenticationException();
        }

        return collect($user->account_roles)
            ->where('account_id', '==', (string) $account->_id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }

    public function tokenAllows(AccountUser $user, string $ability): bool
    {
        $permissions = $this->permissions($user, Account::current());
        $parts = explode(':', $ability, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;

        return in_array("$resource:*", $permissions, true)
            || in_array("$resource:$action", $permissions, true);
    }

    /**
     * @param array<string, mixed> $metadata
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
            '_id' => (string) new ObjectId(),
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
