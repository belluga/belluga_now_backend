<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use App\Models\Tenants\AccountUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use MongoDB\BSON\UTCDateTime;

class PushDeviceService
{
    /**
     * @param Authenticatable $user
     * @param array<string, mixed> $payload
     */
    public function register(Authenticatable $user, array $payload): void
    {
        if (! $user instanceof AccountUser) {
            return;
        }

        $devices = collect($user->devices ?? []);
        $deviceId = $payload['device_id'];

        $index = $devices->search(static fn (array $device): bool => ($device['device_id'] ?? null) === $deviceId);

        $record = [
            'device_id' => $deviceId,
            'platform' => $payload['platform'],
            'push_token' => $payload['push_token'],
            'is_active' => true,
            'invalidated_at' => null,
            'updated_at' => new UTCDateTime(),
        ];

        if ($index === false) {
            $record['created_at'] = new UTCDateTime();
            $devices->push($record);
        } else {
            $devices->put($index, array_merge($devices->get($index), $record));
        }

        $user->devices = $devices->values()->all();
        $user->save();
    }

    /**
     * @param Authenticatable $user
     * @param array<int, string> $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void
    {
        if (! $user instanceof AccountUser || $tokens === []) {
            return;
        }

        $tokensLookup = array_fill_keys($tokens, true);
        $devices = collect($user->devices ?? []);
        $now = new UTCDateTime();

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
     * @param Authenticatable $user
     * @param array<string, mixed> $payload
     */
    public function unregister(Authenticatable $user, array $payload): void
    {
        if (! $user instanceof AccountUser) {
            return;
        }

        $devices = collect($user->devices ?? []);
        $deviceId = $payload['device_id'];

        $devices = $devices->reject(
            static fn (array $device): bool => ($device['device_id'] ?? null) === $deviceId
        );

        $user->devices = $devices->values()->all();
        $user->save();
    }
}
