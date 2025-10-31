<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountUserCredentialService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountUserCredentialServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountUserCredentialService $credentialService;

    private AccountUserService $userService;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole(['account-users:*']);
        $this->account->makeCurrent();

        $this->credentialService = $this->app->make(AccountUserCredentialService::class);
        $this->userService = $this->app->make(AccountUserService::class);
    }

    public function testLinkPasswordCredentialPersistsSecret(): void
    {
        $user = $this->createUser();

        $result = $this->credentialService->link($user, [
            'provider' => 'password',
            'subject' => 'operator@example.org',
            'secret' => 'Secret!234',
        ]);

        /** @var AccountUser $freshUser */
        $freshUser = $result['user'];

        $this->assertNotNull($freshUser->password);
        $this->assertTrue(
            collect($freshUser->credentials)->contains(static function (array $credential): bool {
                return ($credential['provider'] ?? null) === 'password';
            })
        );
    }

    public function testLinkRejectsDuplicateSubject(): void
    {
        $existing = $this->createUser();
        $this->credentialService->link($existing, [
            'provider' => 'password',
            'subject' => 'duplicate@example.org',
            'secret' => 'Secret!234',
        ]);

        $anotherUser = $this->createUser('another@example.org');

        $this->expectExceptionMessage('This credential is already linked to another identity.');

        $this->credentialService->link($anotherUser, [
            'provider' => 'password',
            'subject' => 'duplicate@example.org',
            'secret' => 'Secret!234',
        ]);
    }

    public function testUnlinkRemovesCredential(): void
    {
        $user = $this->createUser();

        $result = $this->credentialService->link($user, [
            'provider' => 'password',
            'subject' => 'operator@example.org',
            'secret' => 'Secret!234',
        ]);

        $credentialId = $result['credential']['_id'] ?? $result['credential']['id'];

        $updated = $this->credentialService->unlink($user->fresh(), (string) $credentialId);

        $this->assertFalse(
            collect($updated->credentials)->contains(static function (array $credential) use ($credentialId): bool {
                $currentId = $credential['_id'] ?? $credential['id'] ?? null;
                return $currentId === $credentialId;
            })
        );
    }

    public function testUnlinkMissingCredentialThrows(): void
    {
        $user = $this->createUser();

        $this->expectException(NotFoundHttpException::class);

        $this->credentialService->unlink($user, (string) Str::uuid());
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Gamma', 'subdomain' => 'tenant-gamma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-gamma.test']
        );

        $service->initialize($payload);
    }

    private function createUser(string $email = 'operator@example.org'): AccountUser
    {
        return $this->userService->create($this->account, [
            'name' => 'Operator',
            'email' => $email,
            'password' => 'Secret!234',
        ], (string) $this->role->_id);
    }
}

