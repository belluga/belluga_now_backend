<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\LandlordUser;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LandlordAuthenticationService
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService
    ) {}

    public function login(string $email, string $password, string $deviceName): AuthenticationResult
    {
        $email = strtolower($email);
        $user = $this->findUserByEmail($email);

        if (! $user || ! $this->credentialsMatch($user, $email, $password)) {
            throw new InvalidCredentialsException;
        }

        $abilities = $user->getPermissions();
        $tenantPermissions = collect($user->tenant_roles ?? [])
            ->pluck('permissions')
            ->flatten()
            ->all();
        $abilities = array_values(array_unique([...$abilities, ...$tenantPermissions]));

        $token = $user->createToken(
            $deviceName,
            $this->sanitizeAbilities($user, $abilities)
        )->plainTextToken;

        return new AuthenticationResult($user, $token);
    }

    public function logout(LandlordUser $user, bool $allDevices, ?string $deviceName = null): void
    {
        if ($allDevices) {
            $this->accessService->revokeTokens($user);

            return;
        }

        if ($deviceName !== null) {
            $this->accessService->revokeTokens($user, $deviceName);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(array $payload): AuthenticationResult
    {
        return DB::connection('landlord')->transaction(function () use ($payload): AuthenticationResult {
            $email = strtolower((string) $payload['email']);
            $secretHash = Hash::make((string) $payload['password']);

            $user = LandlordUser::create([
                'name' => $payload['name'],
                'emails' => [$email],
                'identity_state' => 'registered',
                'promotion_audit' => [],
            ]);

            $this->accessService->ensureEmail($user, $email);
            $this->accessService->syncPasswordCredentialsForEmails($user, $secretHash);
            $this->accessService->removeLegacyPasswordState($user);

            $abilities = $user->getPermissions();
            $tenantPermissions = collect($user->tenant_roles ?? [])
                ->pluck('permissions')
                ->flatten()
                ->all();
            $abilities = array_values(array_unique([...$abilities, ...$tenantPermissions]));

            $token = $user->createToken(
                $payload['device_name'],
                $this->sanitizeAbilities($user, $abilities)
            )->plainTextToken;

            return new AuthenticationResult($user->fresh(), $token);
        });
    }

    private function findUserByEmail(string $email): ?LandlordUser
    {
        return LandlordUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();
    }

    private function credentialsMatch(LandlordUser $user, string $email, string $password): bool
    {
        $credential = $this->accessService->credential($user, 'password', $email);

        if ($credential === null) {
            return false;
        }

        $secretHash = $credential['secret_hash'] ?? null;

        return is_string($secretHash)
            && $secretHash !== ''
            && Hash::check($password, $secretHash);
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(LandlordUser $user, array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            Log::warning('Wildcard abilities expanded to explicit list for landlord token.', [
                'user_id' => (string) $user->_id,
            ]);

            return AbilityCatalog::all();
        }

        return $abilities;
    }
}
