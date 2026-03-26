<?php

namespace Tests\Api\v1\Admin;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\Tenant;
use Illuminate\Testing\TestResponse;
use MongoDB\BSON\ObjectId;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\TestCaseAuthenticated;

class ApiV1AdminAuthTest extends TestCaseAuthenticated
{
    use AccountAuthFunctions;

    protected string $base_api_tenant {
        get {
            return "http://{$this->landlord->tenant_primary->subdomain}.{$this->host}/api/v1/";
        }
    }

    public function test_user_login_wrong_password(): void
    {

        $response = $this->userLoginWrongPassword();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            'errors' => [
                'credentials',
            ],
        ]);

    }

    public function test_user_login_wrong_email(): void
    {

        $response = $this->userLoginWrongEmail();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            'errors' => [
                'credentials',
            ],
        ]);

    }

    public function test_user_login_logout_success_email1(): void
    {

        $response = $this->userLoginSuccessEmail1('device1');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
                'token',
            ],
        ]);

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $response = $this->userLogout('device1');

        $response->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = '';
    }

    public function test_user_login_logout_success_email2(): void
    {

        $this->ensureSecondaryEmailRegistered();

        $response = $this->userLoginSuccessEmail2('device1');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
                'token',
            ],
        ]);

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $response = $this->userLogout('device1');

        $response->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = '';
    }

    public function test_user_login_logout_many_devices_success(): void
    {

        $tokenableId = $this->landlord->user_cross_tenant_admin->user_id;
        PersonalAccessToken::query()
            ->where('tokenable_id', $tokenableId)
            ->orWhere('tokenable_id', new ObjectId($tokenableId))
            ->delete();

        $response = $this->userLoginSuccessEmail1('device1');

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $this->userLoginSuccessEmail1('device2');

        $count = PersonalAccessToken::query()
            ->where('tokenable_id', $tokenableId)
            ->orWhere('tokenable_id', new ObjectId($tokenableId))
            ->count();

        assert($count === 2);

        $response = $this->userLogout(all_devices: true);

        $response->assertStatus(200);

        $response = $this->userLoginSuccessEmail1('default');

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];
    }

    public function test_login_with_token(): void
    {
        $response = $this->userLoginWithToken($this->landlord->user_superadmin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
            ],
        ]);

        $response = $this->userLoginWithToken($this->landlord->user_cross_tenant_admin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
            ],
        ]);
    }

    public function test_login_with_token_error(): void
    {
        $response = $this->userLoginWithToken('123');
        $response->assertStatus(401);
    }

    public function test_admin_token_validate_rejects_tenant_token(): void
    {
        $email = fake()->unique()->safeEmail();
        $password = 'SecurePass!123';

        $tenantBase = "http://{$this->landlord->tenant_primary->subdomain}.{$this->host}/api/v1/";
        $tenantDomain = 'tenant.belluga.test';
        $this->makeCanonicalTenantCurrent();

        $this->json(
            method: 'post',
            uri: "{$tenantBase}auth/register/password",
            data: [
                'name' => 'Tenant Token Check',
                'email' => $email,
                'password' => $password,
            ],
            headers: [
                'X-App-Domain' => $tenantDomain,
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$tenantBase}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-token-check',
            ],
            headers: [
                'X-App-Domain' => $tenantDomain,
            ]
        );

        $login->assertStatus(200);
        $tenantToken = $login->json('data.token');

        $response = $this->json(
            method: 'get',
            uri: 'admin/api/v1/auth/token_validate',
            headers: [
                'Authorization' => "Bearer $tenantToken",
                'Content-Type' => 'application/json',
            ]
        );

        $response->assertStatus(401);
    }

    public function test_admin_login_rejects_password_exceeding_max_length(): void
    {
        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => str_repeat('A', 33),
                'device_name' => 'max-length-check',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password.0', 'The password field must not be greater than 32 characters.');
    }

    public function test_admin_login_rejects_device_name_exceeding_max_length(): void
    {
        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => $this->landlord->user_cross_tenant_admin->password,
                'device_name' => str_repeat('d', 300),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.device_name.0', 'The device name field must not be greater than 255 characters.');
    }

    public function test_admin_login_works_on_tenant_subdomain(): void
    {
        $tenantHost = "{$this->landlord->tenant_primary->subdomain}.{$this->host}";

        $login = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => $this->landlord->user_cross_tenant_admin->password,
                'device_name' => 'tenant-subdomain-login-check',
            ]
        );

        $login->assertStatus(200);
        $login->assertJsonStructure([
            'data' => [
                'user',
                'token',
            ],
        ]);

        $token = $login->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $validate = $this->json(
            method: 'get',
            uri: "http://{$tenantHost}/admin/api/v1/auth/token_validate",
            headers: [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ]
        );

        $validate->assertStatus(200);
        $validate->assertJsonStructure([
            'data' => [
                'user',
            ],
        ]);

        $me = $this->json(
            method: 'get',
            uri: "http://{$tenantHost}/admin/api/v1/me",
            headers: [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ]
        );

        $me->assertStatus(200);
        $me->assertJsonStructure([
            'data' => [
                'user_id',
            ],
        ]);
    }

    public function test_admin_tenant_subdomain_token_can_patch_map_ui_settings_namespace(): void
    {
        $tenantHost = "{$this->landlord->tenant_primary->subdomain}.{$this->host}";
        $tenantId = $this->landlord->tenant_primary->id;

        $crossAdmin = LandlordUser::query()->find($this->landlord->user_cross_tenant_admin->user_id);
        $this->assertNotNull($crossAdmin);

        $crossAdmin->tenant_roles = [[
            'name' => 'Tenant Admin',
            'slug' => 'tenant-admin',
            'permissions' => ['*'],
            'tenant_id' => $tenantId,
        ]];
        $crossAdmin->save();

        $login = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => $this->landlord->user_cross_tenant_admin->password,
                'device_name' => 'tenant-subdomain-map-ui-settings-patch',
            ]
        );

        $login->assertStatus(200);
        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $patch = $this->json(
            method: 'patch',
            uri: "http://{$tenantHost}/admin/api/v1/settings/values/map_ui",
            data: [
                'default_origin.lat' => -20.611121,
                'default_origin.lng' => -40.498617,
                'default_origin.label' => 'Praia do Morro',
            ],
            headers: [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ]
        );

        $patch->assertStatus(200);
        $patch->assertJsonPath('data.default_origin.lat', -20.611121);
        $patch->assertJsonPath('data.default_origin.lng', -40.498617);
        $patch->assertJsonPath('data.default_origin.label', 'Praia do Morro');
    }

    public function test_landlord_tenant_telemetry_endpoints_reject_tenant_outside_operator_access_scope(): void
    {
        $primaryTenant = Tenant::query()
            ->where('slug', $this->landlord->tenant_primary->slug)
            ->firstOrFail();

        $targetTenant = Tenant::query()
            ->where('slug', 'telemetry-bypass-target')
            ->first();
        if (! $targetTenant) {
            $targetTenant = Tenant::create([
                'name' => 'Telemetry Bypass Target',
                'subdomain' => 'telemetry-bypass-target',
                'app_domains' => ['com.telemetry.bypass.target'],
            ]);
        }

        $crossAdmin = LandlordUser::query()->find($this->landlord->user_cross_tenant_admin->user_id);
        $this->assertNotNull($crossAdmin);

        $crossAdmin->tenant_roles = [[
            'name' => 'Tenant Admin',
            'slug' => 'tenant-admin',
            'permissions' => ['telemetry-settings:update'],
            'tenant_id' => (string) $primaryTenant->_id,
        ]];
        $crossAdmin->save();

        $token = $crossAdmin->createToken(
            'telemetry-access-scope-test',
            ['telemetry-settings:update']
        )->plainTextToken;

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];

        $indexResponse = $this->json(
            method: 'get',
            uri: "admin/api/v1/{$targetTenant->slug}/settings/telemetry",
            headers: $headers,
        );
        $indexResponse->assertStatus(404);

        $storeResponse = $this->json(
            method: 'post',
            uri: "admin/api/v1/{$targetTenant->slug}/settings/telemetry",
            data: [
                'type' => 'mixpanel',
                'token' => 'mixpanel-token',
                'track_all' => true,
            ],
            headers: $headers,
        );
        $storeResponse->assertStatus(404);

        $destroyResponse = $this->json(
            method: 'delete',
            uri: "admin/api/v1/{$targetTenant->slug}/settings/telemetry/mixpanel",
            headers: $headers,
        );
        $destroyResponse->assertStatus(404);
    }

    public function test_admin_login_wrong_password_on_tenant_subdomain_returns_credentials_error(): void
    {
        $tenantHost = "{$this->landlord->tenant_primary->subdomain}.{$this->host}";

        $response = $this->json(
            method: 'post',
            uri: "http://{$tenantHost}/admin/api/v1/auth/login",
            data: [
                'email' => $this->landlord->user_cross_tenant_admin->email_1,
                'password' => 'invalid-password',
                'device_name' => 'tenant-subdomain-invalid-password',
            ]
        );

        $response->assertStatus(403);
        $response->assertJsonStructure([
            'errors' => [
                'credentials',
            ],
        ]);
    }

    protected function userLoginWithToken(string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/auth/token_validate',
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );
    }

    protected function userLogout(?string $device = null, ?bool $all_devices = null): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/logout',
            data: $this->payloadUserLogout($device, $all_devices),
            headers: [
                'Authorization' => "Bearer {$this->landlord->user_cross_tenant_admin->token}",
                'Content-Type' => 'application/json',
            ]
        );
    }

    public function test_landlord_anonymous_identity_endpoint_not_available(): void
    {
        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/anonymous/identities',
            data: [
                'device_name' => 'landlord-device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'landlord-device'),
                    'user_agent' => 'AdminTest/1.0',
                ],
            ]
        );

        $this->assertEquals(404, $response->status());
    }

    protected function userLoginWrongPassword(): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: $this->payloadUserLoginWrongPassword()
        );
    }

    protected function userLoginWrongEmail(): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: $this->payloadUserLoginWrongPassword()
        );
    }

    protected function userLoginSuccessEmail1(string $device): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: $this->payloadUserLoginSuccessEmail1($device)
        );
    }

    protected function userLoginSuccessEmail2(string $device): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/auth/login',
            data: $this->payloadUserLoginSuccessEmail2($device)
        );
    }

    protected function payloadUserLogout(?string $device, ?bool $all_devices): array
    {

        $return = [];
        if ($device !== null) {
            $return['device'] = $device;
        }

        if ($all_devices !== null) {
            $return['all_devices'] = $all_devices;
        }

        return $return;
    }

    protected function payloadUserLoginSuccessEmail1(string $device): array
    {
        return [
            'email' => $this->landlord->user_cross_tenant_admin->email_1,
            'password' => $this->landlord->user_cross_tenant_admin->password,
            'device_name' => $device,
        ];
    }

    protected function payloadUserLoginSuccessEmail2(string $device): array
    {
        return [
            'email' => $this->landlord->user_cross_tenant_admin->email_2,
            'password' => $this->landlord->user_cross_tenant_admin->password,
            'device_name' => $device,
        ];
    }

    protected function ensureSecondaryEmailRegistered(): void
    {
        if (empty($this->landlord->user_cross_tenant_admin->email_2)) {
            $this->landlord->user_cross_tenant_admin->email_2 = fake()->unique()->safeEmail();
        }

        $deviceName = 'email2-setup';

        $login = $this->userLoginSuccessEmail1($deviceName);
        $login->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = $login->json('data.token');
        $currentEmails = array_map('strtolower', $login->json('data.user.emails') ?? []);
        $desiredEmail = strtolower($this->landlord->user_cross_tenant_admin->email_2);

        if (! in_array($desiredEmail, $currentEmails, true)) {
            $response = $this->json(
                method: 'patch',
                uri: 'admin/api/v1/profile/emails',
                data: [
                    'email' => $this->landlord->user_cross_tenant_admin->email_2,
                ],
                headers: [
                    'Authorization' => "Bearer {$this->landlord->user_cross_tenant_admin->token}",
                    'Content-Type' => 'application/json',
                ]
            );

            $response->assertStatus(200);
        }

        $this->userLogout($deviceName)->assertStatus(200);
        $this->landlord->user_cross_tenant_admin->token = '';
    }

    protected function payloadUserLoginWrongPassword(): array
    {
        return [
            'email' => $this->landlord->user_cross_tenant_admin->email_1,
            'password' => fake()->password(8),
            'device_name' => 'test',
        ];
    }

    protected function payloadUserLoginWrongEmail(): array
    {
        return [
            'email' => fake()->email(),
            'password' => $this->landlord->user_cross_tenant_admin->password,
            'device_name' => 'test',
        ];
    }
}
