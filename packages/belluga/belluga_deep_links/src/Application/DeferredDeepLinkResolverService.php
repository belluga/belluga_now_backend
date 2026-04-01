<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Application;

class DeferredDeepLinkResolverService
{
    /**
     * @return array<string, mixed>
     */
    public function resolveAndroidInstallReferrer(?string $installReferrer, ?string $fallbackStoreChannel = null): array
    {
        $normalizedReferrer = $this->normalizeText($installReferrer);
        $storeChannelFallback = $this->normalizeText($fallbackStoreChannel);
        if ($normalizedReferrer === null) {
            return [
                'status' => 'not_captured',
                'code' => null,
                'target_path' => '/',
                'store_channel' => $storeChannelFallback,
                'failure_reason' => 'referrer_unavailable',
            ];
        }

        $parsed = $this->parseReferrer($normalizedReferrer);
        $code = $parsed['code'];
        $storeChannel = $parsed['store_channel'] ?? $storeChannelFallback;
        if ($code === null) {
            return [
                'status' => 'not_captured',
                'code' => null,
                'target_path' => '/',
                'store_channel' => $storeChannel,
                'failure_reason' => 'code_missing',
            ];
        }

        return [
            'status' => 'captured',
            'code' => $code,
            'target_path' => '/invite?code='.rawurlencode($code),
            'store_channel' => $storeChannel,
            'failure_reason' => null,
        ];
    }

    /**
     * @return array{code: ?string, store_channel: ?string}
     */
    private function parseReferrer(string $referrer): array
    {
        $queryParams = $this->parseQueryParameters($referrer);
        $directCode = $this->normalizeText($queryParams['code'] ?? null);
        $directStoreChannel = $this->normalizeText(
            $queryParams['store_channel'] ?? $queryParams['utm_source'] ?? $queryParams['channel'] ?? null
        );

        if ($directCode !== null) {
            return [
                'code' => $directCode,
                'store_channel' => $directStoreChannel,
            ];
        }

        foreach (['link', 'deep_link', 'deep_link_value'] as $nestedKey) {
            $rawNested = $this->normalizeText($queryParams[$nestedKey] ?? null);
            if ($rawNested === null) {
                continue;
            }

            $decoded = urldecode($rawNested);
            $nestedQuery = parse_url($decoded, PHP_URL_QUERY);
            $nestedCode = null;
            if (is_string($nestedQuery) && $nestedQuery !== '') {
                $nestedParams = $this->parseQueryParameters($nestedQuery);
                $nestedCode = $this->normalizeText($nestedParams['code'] ?? null);
            }

            if ($nestedCode !== null) {
                return [
                    'code' => $nestedCode,
                    'store_channel' => $directStoreChannel,
                ];
            }
        }

        return [
            'code' => null,
            'store_channel' => $directStoreChannel,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseQueryParameters(string $raw): array
    {
        $normalized = str_starts_with($raw, '?') ? substr($raw, 1) : $raw;
        parse_str($normalized, $queryParams);
        if (! is_array($queryParams)) {
            return [];
        }

        $output = [];
        foreach ($queryParams as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }
            $output[$key] = (string) $value;
        }

        return $output;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
