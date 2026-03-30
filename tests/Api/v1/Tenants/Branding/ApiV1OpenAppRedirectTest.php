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

    public function test_open_app_redirect_for_android_invite_context_preserves_code_and_store_channel(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/invite&code=CODE123&store_channel=web_cta");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $parsed = parse_url((string) $location);
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $this->assertArrayHasKey('referrer', $query);

        $referrer = [];
        parse_str((string) $query['referrer'], $referrer);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
        $this->assertSame('web_cta', $referrer['store_channel'] ?? null);
        $this->assertSame('CODE123', $referrer['code'] ?? null);
        $this->assertSame("{$tenantOrigin}/invite?code=CODE123", $referrer['link'] ?? null);
    }

    public function test_open_app_redirect_non_invite_context_falls_back_to_home_without_code_propagation(): void
    {
        $tenant = $this->makeCanonicalTenantCurrent($this->tenant);
        $this->upsertTypedAppDomain($tenant, Tenant::DOMAIN_TYPE_APP_ANDROID, 'com.guarappari.openapp');

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.guarappari.openapp',
                ],
            ],
        ]);

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 8)')
            ->get("{$this->base_tenant_url}open-app?path=/agenda&code=CODE123&store_channel=web_gate");

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);

        $parsed = parse_url((string) $location);
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $this->assertArrayHasKey('referrer', $query);

        $referrer = [];
        parse_str((string) $query['referrer'], $referrer);

        $tenantOrigin = rtrim((string) $this->base_tenant_url, '/');
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
}
