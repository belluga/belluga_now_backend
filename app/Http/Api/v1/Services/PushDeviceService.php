<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Services;

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
}
