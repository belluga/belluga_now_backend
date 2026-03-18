<?php

declare(strict_types=1);

namespace Tests\Api\v1\Admin;

use Belluga\Settings\Models\Landlord\LandlordSettings;
use Tests\TestCase;

class ApiV1WellKnownAssociationAdminTest extends TestCase
{
    public function test_assetlinks_uses_landlord_settings_payload(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [
                'android' => [
                    'package_name' => 'com.belluga.admin',
                    'sha256_cert_fingerprints' => [
                        '0f:1e:2d:3c:4b:5a:69:78:87:96:a5:b4:c3:d2:e1:f0:11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff:00',
                    ],
                ],
            ],
        ]);

        $response = $this->get('/.well-known/assetlinks.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('0.target.namespace', 'android_app');
        $response->assertJsonPath('0.target.package_name', 'com.belluga.admin');
        $response->assertJsonPath(
            '0.target.sha256_cert_fingerprints.0',
            '0F:1E:2D:3C:4B:5A:69:78:87:96:A5:B4:C3:D2:E1:F0:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00'
        );
    }

    public function test_apple_app_site_association_uses_landlord_settings_payload(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [
                'ios' => [
                    'team_id' => 'LANDLORD1',
                    'bundle_id' => 'com.belluga.admin',
                    'paths' => ['/invite*', '/convites*'],
                ],
            ],
        ]);

        $response = $this->get('/.well-known/apple-app-site-association');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertDontSee('<!DOCTYPE html>', false);
        $response->assertJsonPath('applinks.apps', []);
        $response->assertJsonPath('applinks.details.0.appID', 'LANDLORD1.com.belluga.admin');
        $response->assertJsonPath('applinks.details.0.paths.0', '/invite*');
    }

    public function test_well_known_endpoints_return_json_fallback_when_landlord_credentials_are_missing(): void
    {
        LandlordSettings::query()->delete();
        LandlordSettings::query()->create([
            '_id' => LandlordSettings::ROOT_ID,
            'app_links' => [],
        ]);

        $assetLinks = $this->get('/.well-known/assetlinks.json');
        $assetLinks->assertOk();
        $assetLinks->assertHeader('Content-Type', 'application/json');
        $assetLinks->assertDontSee('<!DOCTYPE html>', false);
        $assetLinks->assertExactJson([]);

        $apple = $this->get('/.well-known/apple-app-site-association');
        $apple->assertOk();
        $apple->assertHeader('Content-Type', 'application/json');
        $apple->assertDontSee('<!DOCTYPE html>', false);
        $apple->assertJsonPath('applinks.apps', []);
        $apple->assertJsonPath('applinks.details', []);
    }
}
