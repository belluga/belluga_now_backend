<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use MongoDB\BSON\UTCDateTime;

class PushUserGatewayAdapter implements PushUserGatewayContract
{
    public function supports(Authenticatable $user): bool
    {
        return $user instanceof AccountUser;
    }

    public function userId(Authenticatable $user): ?string
    {
        if (! $user instanceof AccountUser) {
            return null;
        }

        return (string) $user->getAttribute('_id');
    }

    /**
     * @return array<int, string>
     */
    public function activePushTokens(Authenticatable $user): array
    {
        if (! $user instanceof AccountUser) {
            return [];
        }

        $tokens = [];

        foreach ($user->devices ?? [] as $device) {
            $isActive = $device['is_active'] ?? true;
            if ($isActive !== true) {
                continue;
            }

            $token = $device['push_token'] ?? null;
            if (is_string($token) && $token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return array<int, string>
     */
    public function activePushTokensForDevice(Authenticatable $user, string $deviceId): array
    {
        if (! $user instanceof AccountUser) {
            return [];
        }

        $tokens = [];

        foreach ($user->devices ?? [] as $device) {
            $isActive = $device['is_active'] ?? true;
            if ($isActive !== true) {
                continue;
            }

            if (($device['device_id'] ?? null) !== $deviceId) {
                continue;
            }

            $token = $device['push_token'] ?? null;
            if (is_string($token) && $token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function registerDevice(Authenticatable $user, array $payload): void
    {
        if (! $user instanceof AccountUser) {
            return;
        }

        $devices = collect($user->devices ?? []);
        $deviceId = (string) $payload['device_id'];

        $index = $devices->search(static fn (array $device): bool => ($device['device_id'] ?? null) === $deviceId);
        $record = [
            'device_id' => $deviceId,
            'platform' => (string) $payload['platform'],
            'push_token' => (string) $payload['push_token'],
            'is_active' => true,
            'invalidated_at' => null,
            'updated_at' => new UTCDateTime,
        ];

        if ($index === false) {
            $record['created_at'] = new UTCDateTime;
            $devices->push($record);
        } else {
            $devices->put($index, array_merge($devices->get($index), $record));
        }

        $user->devices = $devices->values()->all();
        $user->save();
    }

    /**
     * @param  array<int, string>  $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void
    {
        if (! $user instanceof AccountUser || $tokens === []) {
            return;
        }

        $tokensLookup = array_fill_keys($tokens, true);
        $devices = collect($user->devices ?? []);
        $now = new UTCDateTime;

        $devices = $devices->map(static function (array $device) use ($tokensLookup, $now): array {
            $token = $device['push_token'] ?? null;
            if (! is_string($token) || $token === '' || ! isset($tokensLookup[$token])) {
                return $device;
            }

            $device['is_active'] = false;
            $device['invalidated_at'] = $now;
            $device['updated_at'] = $now;

            return $device;
        });

        $user->devices = $devices->values()->all();
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unregisterDevice(Authenticatable $user, array $payload): void
    {
        if (! $user instanceof AccountUser) {
            return;
        }

        $devices = collect($user->devices ?? []);
        $deviceId = (string) $payload['device_id'];

        $devices = $devices->reject(
            static fn (array $device): bool => ($device['device_id'] ?? null) === $deviceId
        );

        $user->devices = $devices->values()->all();
        $user->save();
    }

    public function findUserForAccount(string $accountId, ?string $userId, ?string $email): ?Authenticatable
    {
        if ($userId !== null && $userId !== '') {
            return AccountUser::query()
                ->where('_id', $userId)
                ->where('account_roles.account_id', $accountId)
                ->first();
        }

        if ($email !== null && $email !== '') {
            return AccountUser::query()
                ->where('emails', 'all', [strtolower($email)])
                ->where('account_roles.account_id', $accountId)
                ->first();
        }

        return null;
    }

    public function findUserForTenant(?string $userId, ?string $email): ?Authenticatable
    {
        if ($userId !== null && $userId !== '') {
            return AccountUser::query()
                ->where('_id', $userId)
                ->first();
        }

        if ($email !== null && $email !== '') {
            return AccountUser::query()
                ->where('emails', 'all', [strtolower($email)])
                ->first();
        }

        return null;
    }

    /**
     * @param  callable(Authenticatable): void  $callback
     */
    public function chunkUsers(?string $accountId, int $chunkSize, callable $callback): void
    {
        $query = AccountUser::query();

        if ($accountId !== null && $accountId !== '') {
            $query->where('account_roles.account_id', $accountId);
        }

        $query->chunk($chunkSize, static function (Collection $users) use ($callback): void {
            foreach ($users as $user) {
                if ($user instanceof AccountUser) {
                    $callback($user);
                }
            }
        });
    }
}
