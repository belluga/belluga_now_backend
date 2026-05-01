<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1OpenAppRedirectTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function test_open_app_redirect_for_android_invite_context_uses_app_intent_with_store_fallback(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/invite&code=CODE123&store_channel=web_cta");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $intent = $this->parseAndroidIntentLocation((string) $location);
        $this->assertSame('com.guarappari.openapp', $intent['package']);

        $intentData = parse_url($intent['data']);
        parse_str((string) ($intentData['query'] ?? ''), $intentQuery);
        $referrer = [];
        parse_str($this->playStoreReferrerFromUrl($intent['fallback_url']), $referrer);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame(parse_url($tenantOrigin, PHP_URL_HOST), $intentData['host'] ?? null);
        $this->assertSame('/invite', $intentData['path'] ?? null);
        $this->assertSame('CODE123', $intentQuery['code'] ?? null);
        $this->assertSame('web_cta', $referrer['store_channel'] ?? null);
        $this->assertSame('CODE123', $referrer['code'] ?? null);
        $this->assertSame('/invite?code=CODE123', $referrer['target_path'] ?? null);
        $this->assertSame("{$tenantOrigin}/invite?code=CODE123", $referrer['link'] ?? null);
    }

    public function test_open_app_redirect_for_android_pre_guard_context_uses_app_intent_with_promotion_fallback(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/invite&code=CODE123&store_channel=web&fallback=promotion");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $intent = $this->parseAndroidIntentLocation((string) $location);
        $this->assertSame('com.guarappari.openapp', $intent['package']);

        $intentData = parse_url($intent['data']);
        parse_str((string) ($intentData['query'] ?? ''), $intentQuery);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame(parse_url($tenantOrigin, PHP_URL_HOST), $intentData['host'] ?? null);
        $this->assertSame('/invite', $intentData['path'] ?? null);
        $this->assertSame('CODE123', $intentQuery['code'] ?? null);

        $fallback = parse_url($intent['fallback_url']);
        parse_str((string) ($fallback['query'] ?? ''), $fallbackQuery);

        $this->assertSame(parse_url($tenantOrigin, PHP_URL_HOST), $fallback['host'] ?? null);
        $this->assertSame('/baixe-o-app', $fallback['path'] ?? null);
        $this->assertSame('/invite?code=CODE123', $fallbackQuery['redirect'] ?? null);
    }

    public function test_android_public_routes_redirect_through_open_app_intent_on_direct_navigation(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.direct');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.direct',
                ],
            ],
        ]);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $cases = [
            ['url' => $tenantOrigin.'/', 'expected_target_path' => '/'],
            ['url' => $tenantOrigin.'/invite', 'expected_target_path' => '/invite'],
            [
                'url' => $tenantOrigin.'/invite?code=CODE123',
                'expected_target_path' => '/invite?code=CODE123',
            ],
            [
                'url' => $tenantOrigin.'/parceiro/profile-slug',
                'expected_target_path' => '/parceiro/profile-slug',
            ],
            [
                'url' => $tenantOrigin.'/agenda/evento/forro?occurrence=occ-1',
                'expected_target_path' => '/agenda/evento/forro?occurrence=occ-1',
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
                ->get($case['url']);

            $response->assertRedirect();
            $openAppLocation = (string) $response->headers->get('Location');
            $this->assertStringStartsWith($tenantOrigin.'/open-app?', $openAppLocation);

            $intentResponse = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
                ->get($openAppLocation);
            $intentResponse->assertRedirect();

            $intent = $this->parseAndroidIntentLocation(
                (string) $intentResponse->headers->get('Location')
            );
            $this->assertSame('com.guarappari.direct', $intent['package']);

            $intentData = parse_url($intent['data']);
            $intentTarget = ($intentData['path'] ?? '/')
                .(isset($intentData['query']) ? '?'.$intentData['query'] : '');
            $this->assertSame($case['expected_target_path'], $intentTarget);

            $fallback = parse_url($intent['fallback_url']);
            parse_str((string) ($fallback['query'] ?? ''), $fallbackQuery);
            $this->assertSame(
                parse_url($tenantOrigin, PHP_URL_HOST),
                $fallback['host'] ?? null
            );
            $this->assertSame('/baixe-o-app', $fallback['path'] ?? null);
            $this->assertSame($case['expected_target_path'], $fallbackQuery['redirect'] ?? null);
        }
    }

    public function test_open_app_redirect_for_android_public_detail_context_preserves_target_path(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp.detail');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp.detail',
                ],
            ],
        ]);

        $targetPath = '/agenda/evento/forro?occurrence=occ-1';
        $query = http_build_query([
            'path' => $targetPath,
            'store_channel' => 'web_detail',
        ]);
        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?{$query}");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $intent = $this->parseAndroidIntentLocation((string) $location);
        $this->assertSame('com.guarappari.openapp.detail', $intent['package']);

        $intentData = parse_url($intent['data']);
        parse_str((string) ($intentData['query'] ?? ''), $intentQuery);
        $referrer = [];
        parse_str($this->playStoreReferrerFromUrl($intent['fallback_url']), $referrer);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame(parse_url($tenantOrigin, PHP_URL_HOST), $intentData['host'] ?? null);
        $this->assertSame('/agenda/evento/forro', $intentData['path'] ?? null);
        $this->assertSame('occ-1', $intentQuery['occurrence'] ?? null);
        $this->assertSame('web_detail', $referrer['store_channel'] ?? null);
        $this->assertArrayNotHasKey('code', $referrer);
        $this->assertSame($targetPath, $referrer['target_path'] ?? null);
        $this->assertSame("{$tenantOrigin}{$targetPath}", $referrer['link'] ?? null);
    }

    public function test_open_app_redirect_non_invite_context_falls_back_to_home_without_code_propagation(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/agenda&code=CODE123&store_channel=web_gate");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $intent = $this->parseAndroidIntentLocation((string) $location);
        $this->assertSame('com.guarappari.openapp', $intent['package']);

        $intentData = parse_url($intent['data']);
        $referrer = [];
        parse_str($this->playStoreReferrerFromUrl($intent['fallback_url']), $referrer);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame(parse_url($tenantOrigin, PHP_URL_HOST), $intentData['host'] ?? null);
        $this->assertSame('/', $intentData['path'] ?? null);
        $this->assertSame('web_gate', $referrer['store_channel'] ?? null);
        $this->assertArrayNotHasKey('code', $referrer);
        $this->assertSame("{$tenantOrigin}/", $referrer['link'] ?? null);
    }

    public function test_open_app_redirect_falls_back_to_web_target_when_store_url_is_not_configured(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $tenant->domains()->where('type', Tenant::DOMAIN_TYPE_APP_ANDROID)->delete();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/invite&code=CODE123&store_channel=web_cta");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame("{$tenantOrigin}/invite?code=CODE123", $location);
    }

    public function test_open_app_redirect_falls_back_to_web_target_when_publication_is_inactive(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp.inactive');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => false,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp.inactive',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/invite&code=CODE123&store_channel=web_cta");

        $response->assertRedirect();

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame(
            "{$tenantOrigin}/invite?code=CODE123",
            $response->headers->get('Location')
        );
    }

    public function test_open_app_redirect_honors_explicit_platform_target_override(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp.override');
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_IOS, 'com.guarappari.ios.override');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp.override',
                ],
                'ios' => [
                    'enabled' => true,
                    'store_url' => 'https://apps.apple.com/br/app/id123456789',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64)')
            ->get("{$this->base_tenant_url}open-app?path=/profile&store_channel=web_gate&platform_target=ios");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('https://apps.apple.com/br/app/id123456789', (string) $location);

        $parsed = parse_url((string) $location);
        parse_str((string) ($parsed['query'] ?? ''), $query);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame('web_gate', $query['store_channel'] ?? null);
        $this->assertSame("{$tenantOrigin}/profile", $query['deep_link'] ?? null);
    }

    private function upsertTypedAppDomain(Tenant $tenant, string $type, string $identifier): void
    {
        $existing = $tenant->domains()
            ->where('type', $type)
            ->first();

        if ($existing === null) {
            $tenant->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        $existing->save();
    }

    /**
     * @return array{data: string, package: string, fallback_url: string}
     */
    private function parseAndroidIntentLocation(string $location): array
    {
        $this->assertStringStartsWith('intent://', $location);
        $this->assertStringContainsString('#Intent;', $location);
        $this->assertStringEndsWith(';end', $location);

        [$data, $metadata] = explode('#Intent;', $location, 2);
        $this->assertMatchesRegularExpression('/(^|;)scheme=[^;]+;/', $metadata);
        $this->assertMatchesRegularExpression('/;package=([^;]+);/', $metadata);
        $this->assertMatchesRegularExpression('/;S\.browser_fallback_url=([^;]+);/', $metadata);

        preg_match('/;package=([^;]+);/', $metadata, $packageMatches);
        preg_match('/;S\.browser_fallback_url=([^;]+);/', $metadata, $fallbackMatches);

        return [
            'data' => $data,
            'package' => $packageMatches[1] ?? '',
            'fallback_url' => rawurldecode($fallbackMatches[1] ?? ''),
        ];
    }

    private function playStoreReferrerFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $this->assertStringStartsWith('https://play.google.com/store/apps/details', $url);
        $this->assertArrayHasKey('referrer', $query);

        return (string) $query['referrer'];
    }
}
