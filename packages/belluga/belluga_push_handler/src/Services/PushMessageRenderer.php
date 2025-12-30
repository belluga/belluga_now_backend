<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Models\Tenants\PushMessage;
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
        $payload = $message->payload_template ?? [];

        return $this->applyVariables($payload, $variables);
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
}
