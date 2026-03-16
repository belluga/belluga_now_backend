<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1WellKnownAssociationTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function test_assetlinks_uses_tenant_settings_payload(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'package_name' => 'com.guarappari.app',
                    'sha256_cert_fingerprints' => [
                        '3e:72:4c:54:e9:53:26:7d:e6:e1:9b:f8:dc:53:30:2a:08:01:8e:36:40:4d:0c:ca:98:3b:46:84:53:e7:a9:a9',
                    ],
                ],
            ],
        ]);

        $response = $this->get("{$this->base_tenant_url}.well-known/assetlinks.json");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('0.target.namespace', 'android_app');
        $response->assertJsonPath('0.target.package_name', 'com.guarappari.app');
        $response->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            '3E:72:4C:54:E9:53:26:7D:E6:E1:9B:F8:DC:53:30:2A:08:01:8E:36:40:4D:0C:CA:98:3B:46:84:53:E7:A9:A9'
        );
    }

    public function test_apple_app_site_association_uses_tenant_settings_payload(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'ios' => [
                    'team_id' => 'ABCDE12345',
                    'bundle_id' => 'com.guarappari.app',
                    'paths' => ['/invite*', '/convites*'],
                ],
            ],
        ]);

        $response = $this->get("{$this->base_tenant_url}.well-known/apple-app-site-association");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('applinks.apps', []);
        $response->assertJsonPath('applinks.details.0.appID', 'ABCDE12345.com.guarappari.app');
        $response->assertJsonPath('applinks.details.0.paths.0', '/invite*');
    }

    public function test_well_known_endpoints_return_json_fallback_when_tenant_credentials_are_missing(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [],
        ]);

        $assetLinks = $this->get("{$this->base_tenant_url}.well-known/assetlinks.json");
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertExactJson([]);

        $apple = $this->get("{$this->base_tenant_url}.well-known/apple-app-site-association");
        $apple->assertOk();
        $apple->assertHeader('Content-Type', 'application/json');
        $apple->assertDontSee('<!DOCTYPE html>', false);
        $apple->assertJsonPath('applinks.apps', []);
        $apple->assertJsonPath('applinks.details', []);
    }

    public function test_tenant_settings_take_precedence_over_landlord_fallback(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [
                'android' => [
                    'package_name' => 'com.landlord.fallback',
                    'sha256_cert_fingerprints' => [
                        '00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF',
                    ],
                ],
            ],
        ]);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'app_links' => [
                'android' => [
                    'package_name' => 'com.tenant.priority',
                    'sha256_cert_fingerprints' => [
                        'FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00:FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00',
                    ],
                ],
            ],
        ]);

        $assetLinks = $this->get("{$this->base_tenant_url}.well-known/assetlinks.json");
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertJsonPath('0.target.package_name', 'com.tenant.priority');
        $assetLinks->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            'FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00:FF:EE:DD:CC:BB:AA:99:88:77:66:55:44:33:22:11:00'
        );
    }
}
