<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Arr;

class PushMessageRenderer
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function render(PushMessage $message, array $context): array
    {
        $variables = $this->resolveVariables($message, $context);
        $payload = $this->applyVariables($message->payload_template ?? [], $variables);

        $payload = $this->ensureCoreFields($payload, $message, $variables);
        $payload['steps'] = $this->normalizeSteps($payload['steps'] ?? []);
        $payload['buttons'] = $this->normalizeButtons($payload['buttons'] ?? []);

        return $payload;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function resolveVariables(PushMessage $message, array $context): array
    {
        $defaults = $message->template_defaults ?? [];
        $resolved = [];

        foreach ($defaults as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = $entry['key'] ?? null;
            $valuePath = $entry['value'] ?? null;
            $fallback = $entry['default'] ?? '';

            if (! $key || ! $valuePath) {
                continue;
            }

            $resolved[$key] = (string) (Arr::get($context, $valuePath) ?? $fallback);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    private function applyVariables(array $payload, array $variables): array
    {
        $walk = function ($value) use (&$walk, $variables) {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $value[$key] = $walk($item);
                }
                return $value;
            }

            if (is_string($value)) {
                foreach ($variables as $key => $replacement) {
                    $value = str_replace('{{' . $key . '}}', $replacement, $value);
                }
            }

            return $value;
        };

        return $walk($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    private function ensureCoreFields(array $payload, PushMessage $message, array $variables): array
    {
        $payload['title'] = $payload['title'] ?? $this->applyVariablesToString(
            (string) ($message->title_template ?? ''),
            $variables
        );
        $payload['body'] = $payload['body'] ?? $this->applyVariablesToString(
            (string) ($message->body_template ?? ''),
            $variables
        );

        if (! isset($payload['steps']) || ! is_array($payload['steps'])) {
            $payload['steps'] = [];
        }
        if (! isset($payload['buttons']) || ! is_array($payload['buttons'])) {
            $payload['buttons'] = [];
        }

        return $payload;
    }

    /**
     * @param array<int, mixed> $steps
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSteps(array $steps): array
    {
        $normalized = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $buttons
     * @return array<int, array<string, mixed>>
     */
    private function normalizeButtons(array $buttons): array
    {
        $normalized = [];
        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $action = $button['action'] ?? [];
            if (! is_array($action)) {
                $action = [];
            }

            $routeType = ($action['type'] ?? null) === 'external' ? 'externalURL' : 'internalRoute';
            $routeInternal = null;
            $routeExternal = null;
            if ($routeType === 'externalURL') {
                $routeExternal = $action['url'] ?? null;
            } else {
                $routeInternal = $this->buildInternalRoute(
                    $action['route_key'] ?? null,
                    $action['path_parameters'] ?? [],
                    $action['query_parameters'] ?? []
                );
            }

            $normalized[] = [
                'label' => $button['label'] ?? '',
                'routeType' => $routeType,
                'routeInternal' => $routeInternal,
                'routeExternal' => $routeExternal,
                'color' => $button['color'] ?? null,
                'itemKey' => $button['item_key'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $pathParameters
     * @param array<string, mixed> $queryParameters
     */
    private function buildInternalRoute(?string $routeKey, array $pathParameters, array $queryParameters): ?string
    {
        if (! $routeKey) {
            return null;
        }

        $route = $this->routesByKey()[$routeKey] ?? null;
        if (! is_array($route)) {
            return null;
        }

        $path = $route['path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        foreach ($pathParameters as $key => $value) {
            $path = str_replace(':' . $key, rawurlencode((string) $value), $path);
        }

        if ($queryParameters !== []) {
            $query = http_build_query($queryParameters);
            if ($query !== '') {
                $path .= (str_contains($path, '?') ? '&' : '?') . $query;
            }
        }

        return $path;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routesByKey(): array
    {
        $routes = TenantPushSettings::current()?->getPushMessageRoutes() ?? [];
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
            $indexed[$key] = $route;
        }

        return $indexed;
    }

    /**
     * @param array<string, string> $variables
     */
    private function applyVariablesToString(string $value, array $variables): string
    {
        foreach ($variables as $key => $replacement) {
            $value = str_replace('{{' . $key . '}}', $replacement, $value);
        }

        return $value;
    }
}
