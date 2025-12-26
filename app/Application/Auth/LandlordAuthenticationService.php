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
    ) {
    }

    public function login(string $email, string $password, string $deviceName): AuthenticationResult
    {
        $user = $this->findUserByEmail($email);

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw new InvalidCredentialsException();
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
            $user->tokens()->delete();

            return;
        }

        if ($deviceName !== null) {
            $user->tokens()->where('name', $deviceName)->delete();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function register(array $payload): AuthenticationResult
    {
        return DB::connection('landlord')->transaction(function () use ($payload): AuthenticationResult {
            $email = strtolower((string) $payload['email']);

            $user = LandlordUser::create([
                'name' => $payload['name'],
                'emails' => [$email],
                'password' => $payload['password'],
                'identity_state' => 'registered',
                'promotion_audit' => [],
            ]);

            $this->accessService->ensureEmail($user, $email);
            $this->accessService->syncCredential($user, 'password', $email, (string) $user->password);

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

    /**
     * @param array<int, string> $abilities
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
