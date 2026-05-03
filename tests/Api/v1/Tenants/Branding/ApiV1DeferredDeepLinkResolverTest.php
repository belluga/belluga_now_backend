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

    public function test_deferred_resolver_reports_ios_as_not_supported_for_v1(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}deep-links/deferred/resolve", [
            'platform' => 'ios',
            'store_channel' => 'app_store',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'not_captured');
        $response->assertJsonPath('data.code', null);
        $response->assertJsonPath('data.target_path', '/');
        $response->assertJsonPath('data.store_channel', 'app_store');
        $response->assertJsonPath('data.failure_reason', 'unsupported_platform');
    }
}
