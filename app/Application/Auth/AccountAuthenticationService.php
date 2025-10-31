<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\Hash;

class AccountAuthenticationService
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
}

