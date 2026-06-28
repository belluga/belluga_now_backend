<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Branding;

use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1DeferredDeepLinkResolverTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function test_deferred_resolver_captures_android_code_from_install_referrer(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'android',
            'install_referrer' => 'code=ABCD1234&store_channel=play',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', 'ABCD1234');
        $response->assertJsonPath('data.target_path', '/invite?code=ABCD1234');
        $response->assertJsonPath('data.store_channel', 'play');
        $response->assertJsonPath('data.failure_reason', null);
    }

    public function test_deferred_resolver_returns_not_captured_when_code_is_missing(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'android',
            'install_referrer' => 'utm_source=play',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
        $response->assertJsonPath('data.store_channel', 'play');
        $response->assertJsonPath('data.failure_reason', 'code_missing');
    }

    public function test_deferred_resolver_captures_android_target_path_without_invite_code(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'android',
            'install_referrer' => http_build_query([
                'target_path' => '/agenda/evento/forro?occurrence=occ-1',
                'store_channel' => 'play',
            ]),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/agenda/evento/forro?occurrence=occ-1');
        $response->assertJsonPath('data.store_channel', 'play');
        $response->assertJsonPath('data.failure_reason', null);
    }

    public function test_deferred_resolver_captures_ios_deferred_payload(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'ios',
            'deferred_payload' => http_build_query([
                'target_path' => '/profile',
                'store_channel' => 'web_gate',
            ]),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/profile');
        $response->assertJsonPath('data.store_channel', 'web_gate');
        $response->assertJsonPath('data.failure_reason', null);
    }

    public function test_deferred_resolver_returns_not_captured_for_ios_when_payload_is_missing(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'ios',
            'store_channel' => 'web',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
        $response->assertJsonPath('data.store_channel', 'web');
        $response->assertJsonPath('data.failure_reason', 'referrer_unavailable');
    }
}
