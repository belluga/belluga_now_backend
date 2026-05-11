<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Media;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Media\ExternalImageDnsResolverContract;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

final class ExternalImageProxyTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected TenantLabels $tenant_cross {
        get {
            return $this->landlord->tenant_secondary;
        }
    }

    public function test_requires_auth(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
        );

        $response->assertStatus(401);
    }

    public function test_rejects_invalid_url(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'ftp://example.com/image.png'],
            headers: $this->headerFor($this->landlord->user_superadmin),
        );

        $response->assertStatus(422);
    }

    public function test_blocks_private_ip_literal(): void
    {
        $this->bindDnsResolver(['127.0.0.1']);

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'http://127.0.0.1/image.png'],
            headers: $this->headerFor($this->landlord->user_superadmin),
        );

        $response->assertStatus(422);
    }

    public function test_blocks_private_ip_via_dns(): void
    {
        $this->bindDnsResolver(['127.0.0.1']);

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
            headers: $this->headerFor($this->landlord->user_superadmin),
        );

        $response->assertStatus(422);
    }

    public function test_cross_tenant_without_access_is_forbidden(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
            headers: $this->headerFor($this->landlord->user_cross_tenant_visitor),
        );

        $response->assertStatus(403);
    }

    public function test_account_token_is_unauthorized(): void
    {
        /** @var string $tenantId */
        $tenantId = $this->landlord->tenant_primary->id;
        $tenant = \App\Models\Landlord\Tenant::query()->find($tenantId);
        $this->assertNotNull($tenant);
        $tenant->makeCurrent();

        $account = Account::create([
            'name' => 'External Image Account',
            'document' => 'EXTERNALIMAGE01',
        ]);
        $accountUser = $account->users()->create([
            'name' => 'Account User',
            'emails' => ['account-user@belluga.test'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);
        $accountUser->account_roles = [[
            'account_id' => (string) $account->_id,
            'permissions' => ['account-users:create', 'account-users:update'],
            'name' => 'External Image Account Admin',
        ]];
        $accountUser->save();

        $token = $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser(
                $accountUser,
                'external-image-account-token',
                ['account-users:create', 'account-users:update'],
                accountId: (string) $account->_id,
            )
            ->plainTextToken;

        $accountHeaders = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
            headers: $accountHeaders,
        );

        $response->assertStatus(401);
    }

    public function test_successful_proxy_returns_bytes(): void
    {
        $this->bindDnsResolver(['93.184.216.34']);

        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+lmWQAAAAASUVORK5CYII=',
            true
        );
        $this->assertIsString($pngBytes);

        Http::fake([
            'https://example.com/*' => Http::response($pngBytes, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
            headers: $this->headerFor($this->landlord->user_superadmin),
        );

        $response->assertStatus(200);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame($pngBytes, $response->getContent());
    }

    public function test_rejects_oversized_body_without_content_length(): void
    {
        $this->bindDnsResolver(['93.184.216.34']);

        $oversized = str_repeat('a', (15 * 1024 * 1024) + 1);

        Http::fake([
            'https://example.com/*' => Http::response($oversized, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}media/external-image",
            data: ['url' => 'https://example.com/image.png'],
            headers: $this->headerFor($this->landlord->user_superadmin),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url']);
        $this->assertStringContainsString('Maximo 15MB', (string) $response->getContent());
    }

    private function bindDnsResolver(array $ips): void
    {
        $this->app->instance(
            ExternalImageDnsResolverContract::class,
            new class($ips) implements ExternalImageDnsResolverContract
            {
                public function __construct(private readonly array $ips) {}

                public function resolve(string $host): array
                {
                    return $this->ips;
                }
            }
        );
    }

    private function headerFor(UserLabels $user): array
    {
        return [
            'Authorization' => "Bearer {$user->token}",
            'Content-Type' => 'application/json',
        ];
    }
}
