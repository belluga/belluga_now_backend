<?php

declare(strict_types=1);

namespace Tests\Api\v1\Tenants\Identity;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Str;
use Tests\Api\v1\Tenants\Identity\Contracts\ApiV1AnonymousIdentityMergerTestContract;
use Tests\Helpers\TenantLabels;

class T3AnonymousIdentityMergerFailureTest extends ApiV1AnonymousIdentityMergerTestContract
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    public function test_merge_fails_when_anonymous_user_does_not_exist(): void
    {
        $source = $this->createAnonymousSource();
        $this->enablePasswordAuthFixture();

        $response = $this->json('post', sprintf('%sauth/register/password', $this->base_api_tenant), [
            'name' => 'Merge Candidate',
            'email' => sprintf('merge-missing-%s@example.org', Str::uuid()),
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [(string) $source->_id, '60c6e5e5d3f2a3e5c9b7e3a2'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.anonymous_user_ids.0', 'One or more anonymous identities could not be found.');
    }

    public function test_merge_fails_when_not_anonymous_identity(): void
    {
        $source = $this->createCanonicalUser();
        $this->enablePasswordAuthFixture();

        $response = $this->json('post', sprintf('%sauth/register/password', $this->base_api_tenant), [
            'name' => 'Merge Candidate',
            'email' => sprintf('merge-registered-%s@example.org', Str::uuid()),
            'password' => 'SecurePass!123',
            'anonymous_user_ids' => [(string) $source->_id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.anonymous_user_ids.0', 'Only anonymous identities can be merged during registration.');
    }

    private function enablePasswordAuthFixture(): void
    {
        Tenant::forgetCurrent();
        $tenant = $this->ensureCanonicalTenantExists($this->tenant);
        $tenant->makeCurrent();
        $this->setTenantPublicAuthFixture(['password'], tenant: $tenant);
        Tenant::forgetCurrent();
    }
}
