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
        $targetPath = $parsed['target_path'];
        $storeChannel = $parsed['store_channel'] ?? $storeChannelFallback;
        if ($code === null && $targetPath === '/') {
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
            'target_path' => $code === null ? $targetPath : '/invite?code='.rawurlencode($code),
            'store_channel' => $storeChannel,
            'failure_reason' => null,
        ];
    }

    /**
     * @return array{code: ?string, target_path: string, store_channel: ?string}
     */
    private function parseReferrer(string $referrer): array
    {
        $queryParams = $this->parseQueryParameters($referrer);
        $directCode = $this->normalizeText($queryParams['code'] ?? null);
        $directTargetPath = $this->normalizeTargetPath(
            $queryParams['target_path'] ?? $queryParams['path'] ?? null
        );
        $directStoreChannel = $this->normalizeText(
            $queryParams['store_channel'] ?? $queryParams['utm_source'] ?? $queryParams['channel'] ?? null
        );

        if ($directCode !== null) {
            return [
                'code' => $directCode,
                'target_path' => '/invite?code='.rawurlencode($directCode),
                'store_channel' => $directStoreChannel,
            ];
        }

        if ($directTargetPath !== '/') {
            return [
                'code' => null,
                'target_path' => $directTargetPath,
                'store_channel' => $directStoreChannel,
            ];
        }

        foreach (['link', 'deep_link', 'deep_link_value'] as $nestedKey) {
            $rawNested = $this->normalizeText($queryParams[$nestedKey] ?? null);
            if ($rawNested === null) {
                continue;
            }

            $decoded = urldecode($rawNested);
            $nestedTargetPath = $this->normalizeTargetPath($decoded);
            $nestedQuery = parse_url($decoded, PHP_URL_QUERY);
            $nestedCode = null;
            if (is_string($nestedQuery) && $nestedQuery !== '') {
                $nestedParams = $this->parseQueryParameters($nestedQuery);
                $nestedCode = $this->normalizeText($nestedParams['code'] ?? null);
            }

            if ($nestedCode !== null) {
                return [
                    'code' => $nestedCode,
                    'target_path' => '/invite?code='.rawurlencode($nestedCode),
                    'store_channel' => $directStoreChannel,
                ];
            }

            if ($nestedTargetPath !== '/') {
                return [
                    'code' => null,
                    'target_path' => $nestedTargetPath,
                    'store_channel' => $directStoreChannel,
                ];
            }
        }

        return [
            'code' => null,
            'target_path' => '/',
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

    private function normalizeTargetPath(mixed $value): string
    {
        $candidate = $this->normalizeText($value);
        if ($candidate === null || str_starts_with($candidate, '//')) {
            return '/';
        }

        $parts = parse_url($candidate);
        if ($parts === false) {
            return '/';
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        if ($path === '/invite' || $path === '/convites') {
            $queryParams = [];
            if (isset($parts['query']) && is_string($parts['query'])) {
                parse_str($parts['query'], $queryParams);
            }
            $code = $this->normalizeText(is_array($queryParams) ? ($queryParams['code'] ?? null) : null);

            return $code === null ? '/' : '/invite?code='.rawurlencode($code);
        }

        if (! $this->isAllowedTargetPath($path)) {
            return '/';
        }

        $queryParams = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $queryParams);
        }

        $allowedQuery = $this->allowedQueryParametersForPath($path, is_array($queryParams) ? $queryParams : []);

        return $allowedQuery === [] ? $path : $path.'?'.http_build_query($allowedQuery);
    }

    private function normalizePath(string $path): string
    {
        $candidate = trim($path);
        if ($candidate === '') {
            return '/';
        }

        $normalized = str_starts_with($candidate, '/') ? $candidate : '/'.$candidate;
        if (strlen($normalized) > 1 && str_ends_with($normalized, '/')) {
            $normalized = substr($normalized, 0, -1);
        }

        return $normalized;
    }

    private function isAllowedTargetPath(string $path): bool
    {
        if (in_array($path, ['/', '/privacy-policy', '/descobrir', '/mapa', '/mapa/poi', '/location/permission', '/profile', '/convites/compartilhar'], true)) {
            return true;
        }

        if ($this->isEventDetailPath($path)) {
            return true;
        }

        $segments = $this->pathSegments($path);
        if (count($segments) !== 2) {
            return false;
        }

        return in_array($segments[0], ['parceiro', 'static'], true);
    }

    private function isEventDetailPath(string $path): bool
    {
        $segments = $this->pathSegments($path);

        return count($segments) === 3
            && $segments[0] === 'agenda'
            && $segments[1] === 'evento'
            && trim($segments[2]) !== '';
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(
            explode('/', $path),
            fn (string $segment): bool => trim($segment) !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, string>
     */
    private function allowedQueryParametersForPath(string $path, array $queryParams): array
    {
        $allowedKeys = [];
        if ($this->isEventDetailPath($path)) {
            $allowedKeys[] = 'occurrence';
        }
        if ($path === '/mapa' || $path === '/mapa/poi') {
            $allowedKeys[] = 'poi';
            $allowedKeys[] = 'stack';
        }

        $output = [];
        foreach ($allowedKeys as $key) {
            $value = $queryParams[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $output[$key] = trim($value);
            }
        }

        return $output;
    }
}
