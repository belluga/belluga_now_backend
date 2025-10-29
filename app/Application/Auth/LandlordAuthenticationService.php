<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LandlordAuthenticationService
{
    public function login(string $email, string $password, string $deviceName): AuthenticationResult
    {
        $user = $this->findUserByEmail($email);

        if (! $user || ! Hash::check($password, (string) $user->password)) {
            throw new InvalidCredentialsException();
        }

        $token = $user->createToken($deviceName)->plainTextToken;

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

            $user->ensureEmail($email);
            $user->syncCredential('password', $email, (string) $user->password);

            $token = $user->createToken($payload['device_name'])->plainTextToken;

            return new AuthenticationResult($user->fresh(), $token);
        });
    }

    private function findUserByEmail(string $email): ?LandlordUser
    {
        return LandlordUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();
    }
}

