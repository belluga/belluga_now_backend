<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
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
