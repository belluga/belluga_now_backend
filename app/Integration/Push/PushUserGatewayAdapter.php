<?php

declare(strict_types=1);

namespace App\Integration\Push;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Domain\Events\PushTokensInvalidated;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
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

        return trim((string) $user->getAttribute('_id'));
    }

    /**
     * @return array<int, string>
     */
    public function activePushTokens(Authenticatable $user): array
    {
        $userId = $this->userId($user);
        if ($userId === null || $userId === '') {
            return [];
        }

        return $this->normalizeTokens(
            PushDevice::query()
                ->where('account_user_id', $userId)
                ->where('is_active', true)
                ->pluck('push_token')
                ->all()
        );
    }

    /**
     * @return array<int, string>
     */
    public function activePushTokensForDevice(Authenticatable $user, string $deviceId): array
    {
        $userId = $this->userId($user);
        $deviceId = trim($deviceId);
        if ($userId === null || $userId === '' || $deviceId === '') {
            return [];
        }

        return $this->normalizeTokens(
            PushDevice::query()
                ->where('account_user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('is_active', true)
                ->pluck('push_token')
                ->all()
        );
    }

    /**
     * @return array<int, string>
     */
    public function activePushTokensForRecipient(?string $accountId, string $userId, ?string $deviceId = null): array
    {
        $userId = trim($userId);
        $deviceId = trim((string) $deviceId);
        if ($userId === '') {
            return [];
        }

        $query = $this->baseActivePushDeviceQuery($accountId)
            ->where('account_user_id', $userId);

        if ($deviceId !== '') {
            $query->where('device_id', $deviceId);
        }

        return $this->normalizeTokens(
            $query
                ->pluck('push_token')
                ->all()
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function registerDevice(Authenticatable $user, array $payload): void
    {
        if (! $user instanceof AccountUser) {
            return;
        }

        $userId = $this->userId($user);
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $platform = trim((string) ($payload['platform'] ?? ''));
        $pushToken = trim((string) ($payload['push_token'] ?? ''));

        if ($userId === null || $userId === '' || $deviceId === '' || $platform === '' || $pushToken === '') {
            return;
        }

        $now = Carbon::now();
        $accountIds = $this->resolveAccountIds($user);
        $tenantId = $this->currentTenantId();

        /** @var PushDevice|null $device */
        $device = PushDevice::query()
            ->where('account_user_id', $userId)
            ->where('device_id', $deviceId)
            ->first();

        if (! $device instanceof PushDevice) {
            $device = new PushDevice();
            $device->account_user_id = $userId;
            $device->device_id = $deviceId;
        }

        $device->tenant_id = $tenantId;
        $device->account_ids = $accountIds;
        $device->platform = $platform;
        $device->push_token = $pushToken;
        $device->is_active = true;
        $device->invalidated_at = null;
        $device->last_registered_at = $now;
        $device->save();

        $duplicates = PushDevice::query()
            ->where('push_token', $pushToken)
            ->get();

        foreach ($duplicates as $duplicate) {
            if (! $duplicate instanceof PushDevice) {
                continue;
            }

            if ((string) $duplicate->_id === (string) $device->_id) {
                continue;
            }

            $duplicate->is_active = false;
            $duplicate->invalidated_at = $now;
            $duplicate->save();
        }
    }

    /**
     * @param  array<int, string>  $tokens
     */
    public function invalidateTokens(Authenticatable $user, array $tokens): void
    {
        $userId = $this->userId($user);
        $normalizedTokens = $this->normalizeTokens($tokens);
        if ($userId === null || $userId === '' || $normalizedTokens === []) {
            return;
        }

        $now = Carbon::now();
        PushDevice::query()
            ->where('account_user_id', $userId)
            ->whereIn('push_token', $normalizedTokens)
            ->update([
                'is_active' => false,
                'invalidated_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function unregisterDevice(Authenticatable $user, array $payload): void
    {
        $userId = $this->userId($user);
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        if ($userId === null || $userId === '' || $deviceId === '') {
            return;
        }

        $now = Carbon::now();
        PushDevice::query()
            ->where('account_user_id', $userId)
            ->where('device_id', $deviceId)
            ->update([
                'is_active' => false,
                'invalidated_at' => $now,
                'updated_at' => $now,
            ]);
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
     * @return array<int, string>
     */
    public function resolveAccountIds(Authenticatable $user): array
    {
        if (! $user instanceof AccountUser) {
            return [];
        }

        $resolvedIds = array_map(
            static fn (mixed $id): string => trim((string) $id),
            $user->getAccessToIds()
        );

        return array_values(array_unique(array_filter(
            $resolvedIds,
            static fn (string $id): bool => $id !== ''
        )));
    }

    /**
     * @param  array<int, string>  $userIds
     */
    public function countActivePushTargetsByUserIds(?string $accountId, array $userIds): int
    {
        $normalizedUserIds = $this->normalizeIdentifiers($userIds);
        if ($normalizedUserIds === []) {
            return 0;
        }

        return $this->baseActivePushDeviceQuery($accountId)
            ->whereIn('account_user_id', $normalizedUserIds)
            ->count();
    }

    public function countActivePushTargets(?string $accountId): int
    {
        return $this->baseActivePushDeviceQuery($accountId)->count();
    }

    /**
     * @param  array<int, string>  $userIds
     * @param  callable(array<int, array{id:string,user_id:string,push_token:string}>): void  $callback
     */
    public function chunkActivePushTargetsByUserIds(?string $accountId, array $userIds, int $chunkSize, callable $callback): void
    {
        $normalizedUserIds = $this->normalizeIdentifiers($userIds);
        if ($normalizedUserIds === []) {
            return;
        }

        $query = $this->baseActivePushDeviceQuery($accountId)
            ->whereIn('account_user_id', $normalizedUserIds);

        $this->chunkTargetsFromQuery($query, $chunkSize, $callback);
    }

    /**
     * @param  callable(array<int, array{id:string,user_id:string,push_token:string}>): void  $callback
     */
    public function chunkActivePushTargets(?string $accountId, int $chunkSize, callable $callback): void
    {
        $this->chunkTargetsFromQuery(
            $this->baseActivePushDeviceQuery($accountId),
            $chunkSize,
            $callback
        );
    }

    /**
     * @param  array<int, string>  $sourceUserIds
     * @param  array<int, string>  $targetAccountIds
     */
    public function reassignPushDevices(string $targetUserId, array $sourceUserIds, array $targetAccountIds = []): void
    {
        $targetUserId = trim($targetUserId);
        $normalizedSourceUserIds = $this->normalizeIdentifiers($sourceUserIds);
        $normalizedTargetAccountIds = $this->normalizeIdentifiers($targetAccountIds);

        if ($targetUserId === '' || $normalizedSourceUserIds === []) {
            return;
        }

        $tenantId = $this->currentTenantId();

        /** @var iterable<PushDevice> $sourceDevices */
        $sourceDevices = PushDevice::query()
            ->whereIn('account_user_id', $normalizedSourceUserIds)
            ->orderBy('updated_at')
            ->get();

        foreach ($sourceDevices as $sourceDevice) {
            if (! $sourceDevice instanceof PushDevice) {
                continue;
            }

            /** @var PushDevice|null $targetDevice */
            $targetDevice = PushDevice::query()
                ->where('account_user_id', $targetUserId)
                ->where('device_id', (string) $sourceDevice->device_id)
                ->first();

            if (! $targetDevice instanceof PushDevice) {
                $sourceDevice->tenant_id = $tenantId;
                $sourceDevice->account_user_id = $targetUserId;
                $sourceDevice->account_ids = $normalizedTargetAccountIds;
                $sourceDevice->save();

                continue;
            }

            $targetDevice->tenant_id = $tenantId;
            $targetDevice->account_ids = $normalizedTargetAccountIds;
            $targetDevice->platform = (string) ($sourceDevice->platform ?: $targetDevice->platform);
            $targetDevice->push_token = (string) ($sourceDevice->push_token ?: $targetDevice->push_token);
            $targetDevice->is_active = (bool) ($sourceDevice->is_active ?? $targetDevice->is_active);
            $targetDevice->invalidated_at = $sourceDevice->invalidated_at ?? $targetDevice->invalidated_at;
            $targetDevice->last_registered_at = $this->mostRecentTimestamp(
                $targetDevice->last_registered_at,
                $sourceDevice->last_registered_at,
            );
            $targetDevice->save();
            $sourceDevice->delete();
        }
    }

    public function syncPushDeviceAccountIds(string $userId, array $accountIds): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            return;
        }

        PushDevice::query()
            ->where('account_user_id', $userId)
            ->update([
                'account_ids' => $this->normalizeIdentifiers($accountIds),
                'updated_at' => Carbon::now(),
            ]);
    }

    public function deactivatePushDevicesForUser(string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            return;
        }

        $tokens = $this->normalizeTokens(
            PushDevice::query()
                ->where('account_user_id', $userId)
                ->where('is_active', true)
                ->pluck('push_token')
                ->all()
        );

        $now = Carbon::now();
        PushDevice::query()
            ->where('account_user_id', $userId)
            ->update([
                'is_active' => false,
                'invalidated_at' => $now,
                'updated_at' => $now,
            ]);

        if ($tokens !== []) {
            event(new PushTokensInvalidated($tokens));
        }
    }

    private function baseActivePushDeviceQuery(?string $accountId)
    {
        $query = PushDevice::query()->where('is_active', true);

        if ($accountId !== null && $accountId !== '') {
            $query->whereIn('account_ids', [$accountId]);
        }

        return $query;
    }

    /**
     * @param  callable(array<int, array{id:string,user_id:string,push_token:string}>): void  $callback
     */
    private function chunkTargetsFromQuery($query, int $chunkSize, callable $callback): void
    {
        $chunkSize = max(1, $chunkSize);
        $lastSeenId = null;

        while (true) {
            $pageQuery = clone $query;

            if ($lastSeenId !== null) {
                $pageQuery->where('_id', '>', $lastSeenId);
            }

            $batch = $pageQuery
                ->options(['batchSize' => $chunkSize])
                ->orderBy('_id')
                ->limit($chunkSize)
                ->get(['_id', 'account_user_id', 'push_token']);

            if ($batch->isEmpty()) {
                return;
            }

            $targets = [];
            foreach ($batch as $device) {
                if (! $device instanceof PushDevice) {
                    continue;
                }

                $pushToken = trim((string) $device->push_token);
                $userId = trim((string) $device->account_user_id);
                if ($pushToken === '' || $userId === '') {
                    continue;
                }

                $targets[] = [
                    'id' => trim((string) $device->getAttribute('_id')),
                    'user_id' => $userId,
                    'push_token' => $pushToken,
                ];
            }

            if ($targets !== []) {
                $callback($targets);
            }

            $last = $batch->last();
            if (! $last instanceof PushDevice) {
                return;
            }

            $lastSeenId = $last->getAttribute('_id');
            if ($batch->count() < $chunkSize) {
                return;
            }
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeIdentifiers(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeTokens(array $values): array
    {
        return $this->normalizeIdentifiers($values);
    }

    private function currentTenantId(): ?string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return null;
        }

        return trim((string) ($tenant->_id ?? $tenant->id ?? '')) ?: null;
    }

    private function mostRecentTimestamp(mixed $left, mixed $right): mixed
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        $leftCarbon = $this->normalizeTimestamp($left);
        $rightCarbon = $this->normalizeTimestamp($right);

        return $leftCarbon->greaterThanOrEqualTo($rightCarbon) ? $left : $right;
    }

    private function normalizeTimestamp(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse((string) $value);
    }
}
