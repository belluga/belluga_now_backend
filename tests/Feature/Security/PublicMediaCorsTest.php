<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCaseAuthenticated;

#[Group('atlas-critical')]
final class PublicMediaCorsTest extends TestCaseAuthenticated
{
    public function test_public_media_allows_origin_from_same_tenant_custom_domain(): void
    {
        $tenant = $this->currentTenant();
        $customDomain = $this->customDomain();
        $this->registerTenantWebDomain($tenant, $customDomain);
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => "https://{$customDomain}",
        ])->get($this->tenantUrl($tenant, "account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(404);
        $this->assertCorsResponse($response, "https://{$customDomain}");
    }

    public function test_canonical_public_media_allows_origin_from_same_tenant_custom_domain(): void
    {
        $tenant = $this->currentTenant();
        $customDomain = $this->customDomain();
        $this->registerTenantWebDomain($tenant, $customDomain);
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => "https://{$customDomain}",
        ])->get($this->tenantUrl($tenant, "api/v1/media/account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(404);
        $this->assertCorsResponse($response, "https://{$customDomain}");
    }

    public function test_public_media_preflight_is_scoped_to_tenant_domain_set(): void
    {
        $tenant = $this->currentTenant();
        $customDomain = $this->customDomain();
        $this->registerTenantWebDomain($tenant, $customDomain);
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => "https://{$customDomain}",
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Range',
        ])->options($this->tenantUrl($tenant, "account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(204);
        $this->assertCorsResponse($response, "https://{$customDomain}");
        $this->assertSame(['GET, HEAD, OPTIONS'], $response->headers->all('Access-Control-Allow-Methods'));
        $this->assertSame(['Origin, Accept, Range, Content-Type'], $response->headers->all('Access-Control-Allow-Headers'));
        $this->assertSame(['86400'], $response->headers->all('Access-Control-Max-Age'));
    }

    public function test_canonical_public_media_preflight_is_scoped_to_tenant_domain_set(): void
    {
        $tenant = $this->currentTenant();
        $customDomain = $this->customDomain();
        $this->registerTenantWebDomain($tenant, $customDomain);
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => "https://{$customDomain}",
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Range',
        ])->options($this->tenantUrl($tenant, "api/v1/media/account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(204);
        $this->assertCorsResponse($response, "https://{$customDomain}");
        $this->assertSame(['GET, HEAD, OPTIONS'], $response->headers->all('Access-Control-Allow-Methods'));
        $this->assertSame(['Origin, Accept, Range, Content-Type'], $response->headers->all('Access-Control-Allow-Headers'));
        $this->assertSame(['86400'], $response->headers->all('Access-Control-Max-Age'));
    }

    public function test_public_media_rejects_origin_outside_tenant_domain_set(): void
    {
        $tenant = $this->currentTenant();
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.test',
        ])->get($this->tenantUrl($tenant, "account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(404);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_canonical_public_media_rejects_origin_outside_tenant_domain_set(): void
    {
        $tenant = $this->currentTenant();
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.test',
        ])->get($this->tenantUrl($tenant, "api/v1/media/account-profiles/{$profile->getKey()}/avatar"));

        $response->assertStatus(404);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_canonical_public_media_preflight_rejects_origin_outside_tenant_domain_set(): void
    {
        $tenant = $this->currentTenant();
        $profile = $this->createProfile();

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.test',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Range',
        ])->options($this->tenantUrl($tenant, "api/v1/media/account-profiles/{$profile->getKey()}/avatar"));

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Methods'));
    }

    private function registerTenantWebDomain(Tenant $tenant, string $domain): void
    {
        Domains::query()
            ->where('path', $domain)
            ->where('type', Tenant::DOMAIN_TYPE_WEB)
            ->forceDelete();

        $tenant->domains()->create([
            'path' => $domain,
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);
    }

    private function customDomain(): string
    {
        return 'guarappari-'.str_replace('.', '-', uniqid('', true)).'.example.test';
    }

    private function createProfile(): AccountProfile
    {
        $account = Account::query()->create([
            'name' => 'Public Media CORS Account '.uniqid('', true),
            'document' => 'cors-'.uniqid('', true),
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'venue',
            'display_name' => 'Public Media CORS Probe',
            'is_active' => true,
        ]);
    }

    private function assertCorsResponse(
        TestResponse $response,
        string $origin,
        bool $assertNoCredentials = true
    ): void {
        $this->assertSame([$origin], $response->headers->all('Access-Control-Allow-Origin'));
        $this->assertSame(['Origin'], $response->headers->all('Vary'));
        if ($assertNoCredentials) {
            $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        }
    }

    private function currentTenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    private function tenantUrl(Tenant $tenant, string $path): string
    {
        $host = sprintf('%s.%s', $tenant->subdomain, $this->host);

        return sprintf('http://%s/%s', $host, ltrim($path, '/'));
    }
}
