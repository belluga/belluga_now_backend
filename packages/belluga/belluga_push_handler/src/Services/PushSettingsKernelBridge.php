<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushSettingsMutationContract;
use Belluga\PushHandler\Contracts\PushSettingsStoreContract;

class PushSettingsKernelBridge
{
    public function __construct(
        private readonly PushSettingsStoreContract $settingsStore,
        private readonly PushSettingsMutationContract $settingsMutation
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentPushConfig(): array
    {
        $value = $this->settingsStore->getNamespaceValue('push');

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patchPushConfig(mixed $user, array $payload): array
    {
        return $this->settingsMutation->patchNamespace($user, 'push', $payload);
    }

    public function resolveMaxTtlDays(int $default): int
    {
        $value = $this->currentPushConfig()['max_ttl_days'] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function currentFirebaseConfig(): array
    {
        $value = $this->settingsStore->getNamespaceValue('firebase');

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function patchFirebaseConfig(mixed $user, array $payload): array
    {
        return $this->settingsMutation->patchNamespace($user, 'firebase', $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function currentMessageRoutes(): array
    {
        return $this->normalizeItemsList($this->currentPushConfig()['message_routes'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function currentMessageTypes(): array
    {
        return $this->normalizeItemsList($this->currentPushConfig()['message_types'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    public function patchMessageRoutes(mixed $user, array $routes): array
    {
        $payload = [
            'message_routes' => array_values($routes),
        ];

        if (! array_key_exists('max_ttl_days', $this->currentPushConfig())) {
            // Preserve existing wrapper behavior when creating push namespace via this endpoint.
            $payload['max_ttl_days'] = 7;
        }

        $updated = $this->patchPushConfig($user, $payload);

        return $this->normalizeItemsList($updated['message_routes'] ?? []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $types
     * @return array<int, array<string, mixed>>
     */
    public function patchMessageTypes(mixed $user, array $types): array
    {
        $payload = [
            'message_types' => array_values($types),
        ];

        if (! array_key_exists('max_ttl_days', $this->currentPushConfig())) {
            // Preserve existing wrapper behavior when creating push namespace via this endpoint.
            $payload['max_ttl_days'] = 7;
        }

        $updated = $this->patchPushConfig($user, $payload);

        return $this->normalizeItemsList($updated['message_types'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $firebase
     */
    public function hasRequiredFirebaseConfig(array $firebase): bool
    {
        $required = ['apiKey', 'appId', 'projectId', 'messagingSenderId', 'storageBucket'];
        foreach ($required as $key) {
            $value = $firebase[$key] ?? null;
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $push
     * @return array<string, mixed>
     */
    public function extractPushSettingsForResponse(array $push): array
    {
        if ($push === []) {
            return [];
        }

        unset($push['message_routes'], $push['message_types']);
        $push['max_ttl_days'] = is_int($push['max_ttl_days'] ?? null)
            ? $push['max_ttl_days']
            : null;

        return $push;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItemsList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }
}
