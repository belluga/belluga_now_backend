<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Support;

final class PushMessageTemplateNormalizer
{
    /**
     * @param  array<string, mixed>  $payloadTemplate
     * @return array<string, mixed>
     */
    public static function normalize(array $payloadTemplate): array
    {
        $steps = $payloadTemplate['steps'] ?? null;
        if (! is_array($steps)) {
            return $payloadTemplate;
        }

        $updated = false;
        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $stepUpdated = false;
            if (($step['type'] ?? null) === 'selector') {
                $config = $step['config'] ?? null;
                if (is_array($config)) {
                    $selectionMode = $config['selection_mode'] ?? null;
                    if ($selectionMode === null || $selectionMode === '') {
                        $config['selection_mode'] = 'single';
                        $step['config'] = $config;
                        $stepUpdated = true;
                    }
                }
            }

            if (array_key_exists('body', $step) && is_string($step['body'])) {
                $sanitized = PushHtmlSanitizer::sanitize($step['body']);
                if ($sanitized !== $step['body']) {
                    $step['body'] = $sanitized;
                    $stepUpdated = true;
                }
            }

            if (! $stepUpdated) {
                continue;
            }

            $steps[$index] = $step;
            $updated = true;
        }

        if (! $updated) {
            return $payloadTemplate;
        }

        $payloadTemplate['steps'] = $steps;

        return $payloadTemplate;
    }
}
