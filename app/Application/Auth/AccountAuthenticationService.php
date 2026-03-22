<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AccountAuthenticationService
{
    public function __construct(
        private readonly TenantScopedAccessTokenService $tenantScopedAccessTokenService,
    ) {}

    public function login(string $email, string $password, string $deviceName): AuthenticationResult
    {
        $user = $this->findUserByEmail($email);

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw new InvalidCredentialsException;
        }

        $account = Account::current();
        if (! $account) {
            $accessIds = $user->getAccessToIds();
            if ($accessIds !== []) {
                $account = Account::query()
                    ->whereIn('_id', $accessIds)
                    ->first();
            }
        }

        $abilities = $account ? $user->getPermissions($account) : [];

        $token = $this->tenantScopedAccessTokenService
            ->issueForAccountUser(
                $user,
                $deviceName,
                $this->sanitizeAbilities($user, $abilities)
            )
            ->plainTextToken;

        return new AuthenticationResult($user, $token);
    }

    public function logout(AccountUser $user, bool $allDevices, ?string $deviceName = null): void
    {
        if ($allDevices) {
            $user->tokens()->delete();

            return;
        }

        if ($deviceName !== null) {
            $user->tokens()->where('name', $deviceName)->delete();
        }
    }

    private function findUserByEmail(string $email): ?AccountUser
    {
        return AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(AccountUser $user, array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            Log::warning('Wildcard abilities expanded to explicit list for tenant token.', [
                'user_id' => (string) $user->_id,
            ]);

            $catalog = AbilityCatalog::all();

            return array_values(array_filter(
                $catalog,
                static fn (string $ability): bool => str_starts_with($ability, 'account-')
            ));
        }

        return $abilities;
    }
}
