<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Identity;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Application\Identity\TenantPasswordRegistrationResult;
use App\Application\Identity\TenantPasswordRegistrationService;
use App\Domain\Identity\AnonymousIdentityMerger;
use App\Domain\Identity\PasswordIdentityRegistrar;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantPasswordRegistrationServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantPasswordRegistrationService $service;

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

        $this->service = new TenantPasswordRegistrationService(
            $this->app->make(PasswordIdentityRegistrar::class),
            $this->app->make(AnonymousIdentityMerger::class),
            $this->app->make(AccountProfileBootstrapService::class)
        );
    }

    public function test_register_creates_new_user(): void
    {
        $result = $this->service->register($this->tenant, [
            'name' => 'Registered Identity',
            'email' => $this->uniqueEmail('registered'),
            'password' => 'SecurePass!123',
        ]);

        $this->assertInstanceOf(TenantPasswordRegistrationResult::class, $result);
        $this->assertSame('registered', $result->user->identity_state);
        $this->assertTrue(Hash::check('SecurePass!123', (string) $result->user->password));
        $this->assertNotEmpty($result->plainTextToken);
    }

    public function test_register_merges_anonymous_identities(): void
    {
        $first = $this->createAnonymousUser();
        $second = $this->createAnonymousUser();

        $result = $this->service->register($this->tenant, [
            'name' => 'Merged Identity',
            'email' => $this->uniqueEmail('merged'),
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [
                (string) $first->_id,
                (string) $second->_id,
            ],
        ]);

        $this->assertEquals('registered', $result->user->identity_state);
        $this->assertEqualsCanonicalizing(
            [(string) $first->_id, (string) $second->_id],
            $result->user->merged_source_ids ?? []
        );
    }

    public function test_register_rejects_invalid_anonymous_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->register($this->tenant, [
            'name' => 'Invalid Anonymous Identity',
            'email' => $this->uniqueEmail('invalid'),
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => ['not-a-valid-object-id'],
        ]);
    }

    public function test_register_propagates_concurrency_conflict(): void
    {
        $mockMerger = Mockery::mock(AnonymousIdentityMerger::class);
        $mockMerger->shouldReceive('merge')
            ->times(3)
            ->andThrow(new ConcurrencyConflictException);

        $service = new TenantPasswordRegistrationService(
            $this->app->make(PasswordIdentityRegistrar::class),
            $mockMerger,
            $this->app->make(AccountProfileBootstrapService::class)
        );

        $anonymous = $this->createAnonymousUser();

        $this->expectException(ConcurrencyConflictException::class);

        $service->register($this->tenant, [
            'name' => 'Concurrent Identity',
            'email' => $this->uniqueEmail('concurrent'),
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [(string) $anonymous->_id],
        ]);
    }

    private function createAnonymousUser(): AccountUser
    {
        return AccountUser::create([
            'name' => 'Anonymous User',
            'identity_state' => 'anonymous',
            'emails' => [],
            'fingerprints' => [],
            'consents' => [],
            'credentials' => [],
        ]);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(\App\Application\Initialization\SystemInitializationService::class);

        $payload = new \App\Application\Initialization\InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Mu', 'subdomain' => 'tenant-mu'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-mu.test']
        );

        $service->initialize($payload);
    }

    private function uniqueEmail(string $prefix): string
    {
        return sprintf('%s-%s@example.org', $prefix, Str::uuid()->toString());
    }
}
