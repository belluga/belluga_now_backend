<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Domain\Identity\AnonymousIdentityMerger;
use App\Domain\Identity\PasswordIdentityRegistrar;
use App\Exceptions\Identity\IdentityAlreadyExistsException;
use App\Models\Landlord\Tenant;
use App\Support\Auth\AbilityCatalog;
use App\Models\Tenants\AccountUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class TenantPasswordRegistrationService
{
    public function __construct(
        private readonly PasswordIdentityRegistrar $registrar,
        private readonly AnonymousIdentityMerger $identityMerger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @throws IdentityAlreadyExistsException
     */
    public function register(Tenant $tenant, array $payload): TenantPasswordRegistrationResult
    {
        $user = $this->registrar->register([
            'name' => $payload['name'],
            'emails' => [strtolower((string) $payload['email'])],
            'password' => $payload['password'],
        ]);

        $anonymousIds = Collection::make($payload['anonymous_user_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->unique()
            ->values();

        if ($anonymousIds->isNotEmpty()) {
            $tenant->makeCurrent();
            $anonymousUsers = $anonymousIds->map(function (string $id): AccountUser {
                try {
                    $objectId = new ObjectId($id);
                } catch (\Throwable) {
                    throw ValidationException::withMessages([
                        'anonymous_user_ids' => ['One or more anonymous identities was not a valid ObjectId String.'],
                    ]);
                }

                $anonymousUser = AccountUser::query()->find($objectId);

                if ($anonymousUser === null) {
                    throw ValidationException::withMessages([
                        'anonymous_user_ids' => ['One or more anonymous identities could not be found.'],
                    ]);
                }

                if ($anonymousUser->identity_state !== 'anonymous') {
                    throw ValidationException::withMessages([
                        'anonymous_user_ids' => ['Only anonymous identities can be merged during registration.'],
                    ]);
                }

                return $anonymousUser;
            });

            $this->mergeAnonymousUsers($user, $anonymousUsers);
        }

        $abilities = [];
        try {
            $abilities = $user->getPermissions();
        } catch (AuthenticationException) {
            $abilities = [];
        }

        $token = $user->createToken(
            'auth:password-register',
            $this->sanitizeAbilities($abilities)
        );
        $plainToken = $token->plainTextToken;
        $expiresAt = null;

        $policy = $tenant->anonymous_access_policy ?? [];
        if (isset($policy['token_ttl_minutes'])) {
            $minutes = (int) $policy['token_ttl_minutes'];
            $accessToken = $token->accessToken;
            $accessToken->expires_at = now()->addMinutes($minutes);
            $accessToken->save();
            $expiresAt = $accessToken->expires_at;
        }

        return new TenantPasswordRegistrationResult($user, $plainToken, $expiresAt);
    }

    /**
     * @param Collection<int, AccountUser> $anonymousUsers
     */
    private function mergeAnonymousUsers(AccountUser $user, Collection $anonymousUsers): void
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->identityMerger->merge($user, $anonymousUsers, (string) $user->_id);

                return;
            } catch (ConcurrencyConflictException $exception) {
                if ($attempt === $maxAttempts) {
                    throw $exception;
                }

                usleep(100_000);
            }
        }
    }

    /**
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            return AbilityCatalog::all();
        }

        return $abilities;
    }
}
