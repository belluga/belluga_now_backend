<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventCapabilityRegistryContract;
use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Events\Models\Tenants\Event;

class EventCapabilitiesService
{
    public function __construct(
        private readonly EventCapabilityRegistryContract $capabilityRegistry,
        private readonly EventCapabilitySettingsContract $capabilitySettings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    public function resolveEventCapabilities(array $payload, ?Event $existing): array
    {
        $current = $this->normalizeArray($existing?->capabilities ?? []);
        $incomingRootProvided = array_key_exists('capabilities', $payload);
        $incomingRoot = $incomingRootProvided && is_array($payload['capabilities'] ?? null)
            ? $payload['capabilities']
            : [];

        $resolved = [];
        foreach ($this->capabilityRegistry->all() as $handler) {
            $key = $handler->key();
            $currentConfig = $this->normalizeArray($current[$key] ?? []);
            $incomingConfig = null;

            if ($incomingRootProvided && array_key_exists($key, $incomingRoot) && is_array($incomingRoot[$key])) {
                $incomingConfig = $incomingRoot[$key];
            }

            $resolved[$key] = $handler->mergeEventConfig($incomingConfig, $currentConfig);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function shouldPersistCapabilities(array $payload, ?Event $existing): bool
    {
        return $existing === null || array_key_exists('capabilities', $payload);
    }

    /**
     * @param array<string, array<string, mixed>> $eventCapabilities
     * @param array<int, array<string, mixed>> $occurrences
     */
    public function assertScheduleConstraints(array $eventCapabilities, array $occurrences): void
    {
        if (count($occurrences) <= 1) {
            return;
        }

        $tenantCapabilities = $this->resolveTenantCapabilities();

        foreach ($this->capabilityRegistry->all() as $handler) {
            $key = $handler->key();
            $tenantConfig = $tenantCapabilities[$key] ?? $handler->normalizeTenantConfig(null);
            $eventConfig = $eventCapabilities[$key] ?? $handler->mergeEventConfig(null, []);

            $handler->assertScheduleConstraints($eventConfig, $tenantConfig, $occurrences);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveTenantCapabilities(): array
    {
        $rawCapabilities = $this->normalizeArray($this->capabilitySettings->resolveTenantCapabilities());
        $resolved = [];

        foreach ($this->capabilityRegistry->all() as $handler) {
            $key = $handler->key();
            $rawConfig = $this->normalizeArray($rawCapabilities[$key] ?? []);
            $resolved[$key] = $handler->normalizeTenantConfig($rawConfig);
        }

        return $resolved;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }
}

