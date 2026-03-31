<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Application;

class WebToAppPromotionService
{
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
        $candidate = trim((string) $path);
        if ($candidate === '') {
            return '/';
        }

        if (! str_starts_with($candidate, '/')) {
            $candidate = '/'.$candidate;
        }

        if ($candidate === '/invite' || $candidate === '/convites') {
            return $candidate;
        }

        return '/';
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
        $isInviteContext = ($targetPath === '/invite' || $targetPath === '/convites') && $code !== null;
        if (! $isInviteContext) {
            return $origin.'/';
        }

        return $origin.'/invite?code='.rawurlencode($code);
    }

    private function resolvePropagatedCode(string $targetPath, ?string $code): ?string
    {
        $isInviteContext = ($targetPath === '/invite' || $targetPath === '/convites');
        if (! $isInviteContext) {
            return null;
        }

        return $code;
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
