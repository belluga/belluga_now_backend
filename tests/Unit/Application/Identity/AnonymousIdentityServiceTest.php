<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Identity;

use App\Application\Identity\AnonymousIdentityResult;
use App\Application\Identity\AnonymousIdentityService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AnonymousIdentityServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private AnonymousIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();

        $this->service = $this->app->make(AnonymousIdentityService::class);
    }

    public function testRegisterCreatesNewAnonymousIdentity(): void
    {
        $result = $this->service->register($this->tenant, [
            'device_name' => 'test-device',
            'fingerprint' => [
                'hash' => 'hash-1',
                'user_agent' => 'PHPUnit',
            ],
            'metadata' => ['foo' => 'bar'],
        ]);

        $this->assertInstanceOf(AnonymousIdentityResult::class, $result);
        $this->assertSame('anonymous', $result->user->identity_state);
        $this->assertNotEmpty($result->plainTextToken);
    }

    public function testRegisterUpdatesExistingFingerprint(): void
    {
        $payload = [
            'device_name' => 'test-device',
            'fingerprint' => [
                'hash' => 'hash-2',
                'user_agent' => 'PHPUnit',
                'locale' => 'en-US',
            ],
        ];

        $first = $this->service->register($this->tenant, $payload);
        $second = $this->service->register($this->tenant, $payload);

        $this->assertSame((string) $first->user->_id, (string) $second->user->_id);
        $this->assertCount(1, $second->user->fingerprints ?? []);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant One', 'subdomain' => 'tenant-one'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-one.test']
        );

        $service->initialize($payload);
    }
}
