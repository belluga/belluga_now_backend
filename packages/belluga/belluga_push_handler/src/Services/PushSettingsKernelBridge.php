<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\Settings\Application\SettingsKernelService;
use Belluga\Settings\Contracts\SettingsStoreContract;

class PushSettingsKernelBridge
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
        private readonly SettingsKernelService $settingsKernelService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function currentPushConfig(): array
    {
        $value = $this->settingsStore->getNamespaceValue('tenant', 'push');

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function patchPushConfig(mixed $user, array $payload): array
    {
        return $this->settingsKernelService->patchNamespace('tenant', $user, 'push', $payload);
    }

    public function resolveMaxTtlDays(int $default): int
    {
        $value = $this->currentPushConfig()['max_ttl_days'] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $push
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
}
