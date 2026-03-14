<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

class PushRouteCatalog
{
    /**
     * @return array<int, string>
     */
    public function routeKeys(PushSettingsKernelBridge $pushSettings): array
    {
        return array_values(array_keys($this->routesByKey($pushSettings)));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function routesByKey(PushSettingsKernelBridge $pushSettings): array
    {
        $routes = $pushSettings->currentMessageRoutes();
        if (! is_array($routes)) {
            return [];
        }

        $indexed = [];
        foreach ($routes as $route) {
            if (! is_array($route)) {
                continue;
            }

            $key = $route['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (! $this->isActiveEntry($route)) {
                continue;
            }

            $indexed[$key] = $route;
        }

        return $indexed;
    }

    /**
     * @return array<int, string>|null
     */
    public function allowedRouteKeysForValidation(
        PushSettingsKernelBridge $pushSettings,
        ?string $messageType,
        ?array $routes = null
    ): ?array {
        $allowedKeys = $this->allowedRouteKeys($pushSettings, $messageType);
        if ($allowedKeys === null) {
            return null;
        }

        $routes ??= $this->routesByKey($pushSettings);
        $activeKeys = array_keys($routes);
        $filtered = [];

        foreach ($allowedKeys as $key) {
            if (in_array($key, $activeKeys, true)) {
                $filtered[] = $key;
            }
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param  array<int, string>  $allowedKeys
     */
    public function formatAllowedRouteKeysMessage(array $allowedKeys): string
    {
        if ($allowedKeys === []) {
            return 'Route key is not allowed for this message type. No route keys are allowed for this message type.';
        }

        return sprintf(
            'Route key is not allowed for this message type. Allowed route keys: %s.',
            implode(', ', $allowedKeys)
        );
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedRouteKeys(PushSettingsKernelBridge $pushSettings, ?string $messageType): ?array
    {
        if (! is_string($messageType) || $messageType === '') {
            return null;
        }

        $types = $pushSettings->currentMessageTypes();
        if (! is_array($types)) {
            return null;
        }

        foreach ($types as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['key'] ?? null) !== $messageType) {
                continue;
            }

            if (! $this->isActiveEntry($entry)) {
                return [];
            }

            $allowed = $entry['allowed_route_keys'] ?? null;
            if (! is_array($allowed)) {
                return null;
            }

            $allowedKeys = [];
            foreach ($allowed as $key) {
                if (is_string($key) && $key !== '') {
                    $allowedKeys[] = $key;
                }
            }

            return array_values(array_unique($allowedKeys));
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isActiveEntry(array $entry): bool
    {
        $active = $entry['active'] ?? true;

        return $active !== false;
    }
}
