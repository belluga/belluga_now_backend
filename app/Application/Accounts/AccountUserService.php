<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Domain\FoundationControlPlane\Identity\Exceptions\IdentityAlreadyExistsException;
use App\Domain\Identity\PasswordIdentityRegistrar;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\ObjectId;

class AccountUserService
{
    public function __construct(
        private readonly PasswordIdentityRegistrar $passwordIdentityRegistrar,
        private readonly AccountUserAccessService $accessService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Account $account, array $payload, string $roleId): AccountUser
    {
        return DB::connection('tenant')->transaction(function () use ($account, $payload, $roleId): AccountUser {
            $normalizedPayload = $this->normalizeCreatePayload($payload);

            $user = $this->findOrCreateUser($normalizedPayload);

            if (! $user->isActive()) {
                $user->restore();
            }

            if (! $user->haveAccessTo($account)) {
                $roleTemplate = AccountRoleTemplate::query()
                    ->where('_id', new ObjectId($roleId))
                    ->firstOrFail();

                $user->accountRoles()->create([
                    ...$roleTemplate->attributesToArray(),
                    'account_id' => $account->id,
                ]);
            }

            return $user->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AccountUser $user, array $attributes): AccountUser
    {
        $payload = Arr::except($attributes, ['password']);

        if (array_key_exists('password', $attributes) && $attributes['password'] !== null) {
            $passwordHash = Hash::make($attributes['password']);
            $user->password = $passwordHash;

            foreach ($user->emails ?? [] as $email) {
                $this->accessService->syncCredential($user, 'password', $email, $passwordHash);
            }
        }

        if ($payload !== []) {
            $user->fill($payload);
        }

        $user->save();

        return $user->fresh();
    }

    public function remove(Account $account, AccountUser $user): void
    {
        DB::connection('tenant')->transaction(function () use ($account, $user): void {
            $user->accountRoles()
                ->where('account_id', $account->id)
                ->first()
                ?->delete();

            if (count($user->getAccessToIds()) === 0) {
                $user->delete();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizeCreatePayload(array $payload): array
    {
        $email = strtolower((string) ($payload['email'] ?? $payload['emails'][0] ?? ''));

        return [
            ...$payload,
            'emails' => [$email],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrCreateUser(array $payload): AccountUser
    {
        $user = AccountUser::withTrashed()
            ->whereRaw([
                'emails' => ['$in' => $payload['emails']],
            ])
            ->first();

        if (! $user) {
            try {
                return $this->passwordIdentityRegistrar->register(
                    Arr::except($payload, ['role_id'])
                );
            } catch (IdentityAlreadyExistsException) {
                abort(422, 'An identity with the provided contact points already exists.');
            }
        }

        if ($user->trashed()) {
            $user->restore();
        }

        if (! empty($payload['emails'])) {
            foreach ($payload['emails'] as $email) {
                $this->accessService->ensureEmail($user, $email);
            }
        }

        if (! empty($payload['password'])) {
            $passwordHash = Hash::make($payload['password']);
            $user->password = $passwordHash;

            foreach ($payload['emails'] as $email) {
                $this->accessService->syncCredential($user, 'password', $email, $passwordHash);
            }
        }

        return $user;
    }
}
