<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class PasswordRegistrationControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            Tenant::query()->firstOrFail()->update([
                'app_domains' => ['tenant-nu.test'],
            ]);
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_registers_new_identity(): void
    {
        $email = sprintf(
            'feature-registered-%s@example.org',
            (string) Str::uuid()
        );

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/register/password', 'tenant-nu', $this->host), [
                'name' => 'Feature Registered User',
                'email' => $email,
                'password' => 'Secret!234',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.identity_state', 'registered');

        $userId = $response->json('data.user_id');
        Tenant::query()->firstOrFail()->makeCurrent();
        $user = \App\Models\Tenants\AccountUser::query()->findOrFail(new ObjectId($userId));
        $this->assertSame('registered', $user->identity_state);
        $this->assertTrue(Hash::check('Secret!234', (string) $user->password));
    }

    public function test_registers_new_identity_when_tenant_context_key_was_lost(): void
    {
        Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));

        $email = sprintf(
            'feature-context-rebound-%s@example.org',
            (string) Str::uuid()
        );

        $response = $this->withHeaders(['X-App-Domain' => 'tenant-nu.test'])
            ->postJson(sprintf('http://%s.%s/api/v1/auth/register/password', 'tenant-nu', $this->host), [
                'name' => 'Feature Context Rebound User',
                'email' => $email,
                'password' => 'Secret!234',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.identity_state', 'registered');
    }

    public function test_password_auth_routes_are_quarantined_when_password_is_not_effective(): void
    {
        $landlordSettings = LandlordSettings::current() ?? new LandlordSettings;
        $tenantSettings = TenantSettings::current() ?? new TenantSettings;
        $originalLandlordAuth = $landlordSettings->getAttribute('tenant_public_auth');
        $originalTenantAuth = $tenantSettings->getAttribute('tenant_public_auth');

        try {
            $landlordSettings->setAttribute('_id', $landlordSettings->getAttribute('_id') ?? 'settings_root');
            $landlordSettings->setAttribute('tenant_public_auth', [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => true,
            ]);
            $landlordSettings->save();

            $tenantSettings->setAttribute('_id', $tenantSettings->getAttribute('_id') ?? 'settings_root');
            $tenantSettings->setAttribute('tenant_public_auth', [
                'enabled_methods' => ['phone_otp'],
            ]);
            $tenantSettings->save();

            $baseUrl = sprintf('http://%s.%s/api/v1/auth', 'tenant-nu', $this->host);
            $headers = ['X-App-Domain' => 'tenant-nu.test'];

            $login = $this->withHeaders($headers)
                ->postJson($baseUrl.'/login', [
                    'email' => 'blocked-login@example.org',
                    'password' => 'Secret!234',
                    'device_name' => 'api-client',
                ]);
            $login->assertStatus(422);
            $login->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $register = $this->withHeaders($headers)
                ->postJson($baseUrl.'/register/password', [
                    'name' => 'Blocked Registration',
                    'email' => 'blocked-register@example.org',
                    'password' => 'Secret!234',
                ]);
            $register->assertStatus(422);
            $register->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $passwordToken = $this->withHeaders($headers)
                ->postJson($baseUrl.'/password_token', [
                    'email' => 'blocked-reset@example.org',
                ]);
            $passwordToken->assertStatus(422);
            $passwordToken->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');

            $passwordReset = $this->withHeaders($headers)
                ->postJson($baseUrl.'/password_reset', [
                    'email' => 'blocked-reset@example.org',
                    'password' => 'Secret!234',
                    'password_confirmation' => 'Secret!234',
                    'reset_token' => 'not-used-when-password-is-disabled',
                ]);
            $passwordReset->assertStatus(422);
            $passwordReset->assertJsonPath('errors.auth_method.0', 'Password authentication is not enabled for this tenant.');
        } finally {
            $landlordSettings->setAttribute('tenant_public_auth', $originalLandlordAuth);
            $landlordSettings->save();

            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Nu', 'subdomain' => 'tenant-nu'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-nu.test']
        );

        $service->initialize($payload);
    }
}
