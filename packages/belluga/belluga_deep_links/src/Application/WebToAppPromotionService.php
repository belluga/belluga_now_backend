<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Application;

class WebToAppPromotionService
{
    private const int MAX_TARGET_PATH_LENGTH = 2048;

    private const int MAX_REDIRECT_UNWRAP_DEPTH = 5;

    public function __construct(
        private readonly DeepLinkAssociationService $associationService
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function resolveRedirectUrl(
        string $origin,
        string $platformTarget,
        string $targetPath,
        ?string $code,
        string $storeChannel,
        array $settings,
    ): string {
        $normalizedOrigin = rtrim($origin, '/');
        $propagatedCode = $this->resolvePropagatedCode(
            targetPath: $targetPath,
            code: $code,
        );
        $openTargetUrl = $this->buildOpenTargetUrl(
            origin: $normalizedOrigin,
            targetPath: $targetPath,
            code: $propagatedCode,
        );

        if ($platformTarget === 'android') {
            return $this->resolveAndroidRedirect(
                openTargetUrl: $openTargetUrl,
                code: $propagatedCode,
                storeChannel: $storeChannel,
                settings: $settings,
            );
        }

        if ($platformTarget === 'ios') {
            return $this->resolveIosRedirect(
                openTargetUrl: $openTargetUrl,
                code: $propagatedCode,
                storeChannel: $storeChannel,
                settings: $settings,
            );
        }

        return $openTargetUrl;
    }

    public function detectPlatformTarget(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));
        if ($ua !== '' && str_contains($ua, 'android')) {
            return 'android';
        }
        if ($ua !== '' && (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios'))) {
            return 'ios';
        }

        return 'web';
    }

    public function normalizePlatformTarget(?string $platformTarget): ?string
    {
        $candidate = strtolower(trim((string) $platformTarget));
        if ($candidate === '') {
            return null;
        }

        if ($candidate === 'android' || $candidate === 'ios') {
            return $candidate;
        }

        return null;
    }

    public function normalizeTargetPath(?string $path): string
    {
        return $this->normalizeTargetPathInternal(
            path: $path,
            includeAuthOwnedAppPaths: true,
        ) ?? '/';
    }

    public function normalizeCode(?string $code): ?string
    {
        $candidate = trim((string) $code);

        return $candidate === '' ? null : $candidate;
    }

    public function normalizeStoreChannel(?string $storeChannel): string
    {
        $candidate = strtolower(trim((string) $storeChannel));
        if ($candidate === '') {
            return 'web';
        }

        $safe = preg_replace('/[^a-z0-9_\-]/', '', $candidate);
        if (! is_string($safe) || $safe === '') {
            return 'web';
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveAndroidRedirect(
        string $openTargetUrl,
        ?string $code,
        string $storeChannel,
        array $settings,
    ): string {
        $storeUrl = $this->associationService->resolveAndroidStoreUrl($settings);
        if ($storeUrl === null) {
            return $openTargetUrl;
        }

        $referrerParams = [
            'store_channel' => $storeChannel,
            'link' => $openTargetUrl,
            'target_path' => $this->targetPathFromOpenTargetUrl($openTargetUrl),
        ];
        if ($code !== null) {
            $referrerParams['code'] = $code;
        }

        return $this->appendQuery(
            $storeUrl,
            ['referrer' => http_build_query($referrerParams)],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveIosRedirect(
        string $openTargetUrl,
        ?string $code,
        string $storeChannel,
        array $settings,
    ): string {
        $storeUrl = $this->associationService->resolveIosStoreUrl($settings);
        if ($storeUrl === null) {
            return $openTargetUrl;
        }

        $params = [
            'store_channel' => $storeChannel,
            'deep_link' => $openTargetUrl,
        ];
        if ($code !== null) {
            $params['code'] = $code;
        }

        return $this->appendQuery($storeUrl, $params);
    }

    private function buildOpenTargetUrl(
        string $origin,
        string $targetPath,
        ?string $code,
    ): string {
        $pathOnly = $this->pathOnly($targetPath);
        $isInviteContext = ($pathOnly === '/invite' || $pathOnly === '/convites') && $code !== null;
        if ($isInviteContext) {
            return $origin.'/invite?code='.rawurlencode($code);
        }

        if ($targetPath === '/' || $pathOnly === '/invite' || $pathOnly === '/convites') {
            return $origin.'/';
        }

        return $origin.$targetPath;
    }

    private function resolvePropagatedCode(string $targetPath, ?string $code): ?string
    {
        $pathOnly = $this->pathOnly($targetPath);
        $isInviteContext = ($pathOnly === '/invite' || $pathOnly === '/convites');
        if (! $isInviteContext) {
            return null;
        }

        if ($code !== null) {
            return $code;
        }

        $parts = parse_url($targetPath);
        if ($parts === false || ! isset($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $params);
        $targetCode = $params['code'] ?? null;

        return is_string($targetCode) && trim($targetCode) !== '' ? trim($targetCode) : null;
    }

    private function normalizeTargetPathInternal(
        ?string $path,
        bool $includeAuthOwnedAppPaths,
        int $unwrapDepth = 0,
    ): ?string {
        $candidate = trim((string) $path);
        if ($candidate === '' || strlen($candidate) > self::MAX_TARGET_PATH_LENGTH || str_starts_with($candidate, '//')) {
            return null;
        }

        if (! str_starts_with($candidate, '/')) {
            $candidate = '/'.$candidate;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $normalizedPath = $this->normalizePath($parts['path'] ?? '/');
        if ($normalizedPath === '/baixe-o-app') {
            return null;
        }

        $queryParams = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            if (! is_array($queryParams)) {
                $queryParams = [];
            }
        }

        if ($normalizedPath === '/auth' || str_starts_with($normalizedPath, '/auth/')) {
            if ($unwrapDepth >= self::MAX_REDIRECT_UNWRAP_DEPTH) {
                return null;
            }

            $nestedRedirect = $queryParams['redirect'] ?? null;
            if (! is_string($nestedRedirect) || trim($nestedRedirect) === '') {
                return null;
            }

            return $this->normalizeTargetPathInternal(
                path: $nestedRedirect,
                includeAuthOwnedAppPaths: $includeAuthOwnedAppPaths,
                unwrapDepth: $unwrapDepth + 1,
            );
        }

        if ($normalizedPath === '/invite' || $normalizedPath === '/convites') {
            $code = $queryParams['code'] ?? null;
            if (is_string($code) && trim($code) !== '') {
                return '/invite?code='.rawurlencode(trim($code));
            }

            return $normalizedPath;
        }

        if ($this->isAuthOwnedContinuationPath($normalizedPath)) {
            return $includeAuthOwnedAppPaths ? $normalizedPath : null;
        }

        if (! $this->isAllowedPublicContinuationPath($normalizedPath)) {
            return null;
        }

        $allowedQuery = $this->allowedQueryParametersForPath($normalizedPath, $queryParams);
        if ($allowedQuery === []) {
            return $normalizedPath;
        }

        return $normalizedPath.'?'.http_build_query($allowedQuery);
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

    private function pathOnly(string $targetPath): string
    {
        $parts = parse_url($targetPath);

        return $this->normalizePath(is_array($parts) ? (string) ($parts['path'] ?? '/') : '/');
    }

    private function targetPathFromOpenTargetUrl(string $openTargetUrl): string
    {
        $parts = parse_url($openTargetUrl);
        if ($parts === false) {
            return '/';
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return $path.$query;
    }

    private function isAuthOwnedContinuationPath(string $path): bool
    {
        return $path === '/profile' || $path === '/convites/compartilhar';
    }

    private function isAllowedPublicContinuationPath(string $path): bool
    {
        if (in_array($path, ['/', '/privacy-policy', '/descobrir', '/mapa', '/mapa/poi', '/location/permission'], true)) {
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

    /**
     * @param  array<string, string>  $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $existing = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        $merged = array_merge($existing, $params);
        $query = http_build_query($merged);

        $rebuilt = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if ($query !== '') {
            $rebuilt .= '?'.$query;
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $rebuilt .= '#'.$parts['fragment'];
        }

        return $rebuilt;
    }
}
