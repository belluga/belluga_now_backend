<?php

namespace Tests;

use App\Application\LandlordTenants\TenantLifecycleService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Support\Auth\AbilityCatalog;
use Tests\Helpers\TenantLabels;
use Tests\Traits\EnsuresSystemInitialization;

abstract class TestCaseAuthenticated extends TestCase
{
    use EnsuresSystemInitialization;

    private ?string $cachedAdminToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAuthenticatedHarnessState();
        $this->ensureSystemInitialized();
    }

    protected function prepareAuthenticatedHarnessState(): void
    {
    }

    protected function ensureCanonicalTenantExists(?TenantLabels $labels = null): Tenant
    {
        $labels ??= $this->landlord->tenant_primary;

        try {
            $tenant = $this->resolveCanonicalTenant($labels);
        } catch (\RuntimeException) {
            /** @var TenantLifecycleService $lifecycle */
            $lifecycle = app(TenantLifecycleService::class);

            $operator = LandlordUser::query()->find($this->landlord->user_superadmin->user_id)
                ?? LandlordUser::query()->first();

            if (! $operator instanceof LandlordUser) {
                throw new \RuntimeException('Unable to provision canonical tenant without a landlord operator.');
            }

            $created = $lifecycle->create([
                'name' => $labels->name,
                'subdomain' => $labels->subdomain,
            ], $operator);

            /** @var Tenant $tenant */
            $tenant = $created['tenant'];
            $role = $created['role'];

            $labels->role_admin->id = (string) $role->_id;
            $labels->role_admin->name = $role->name;
        }

        $labels->id = (string) $tenant->_id;
        $labels->slug = $tenant->slug;
        $labels->subdomain = $tenant->subdomain;
        $labels->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? $labels->role_admin->id);

        return $tenant;
    }

    protected string $base_api_url {
        get {
            return 'admin/api/v1/';
        }
    }

    protected function getHeaders(): array
    {

        if ($this->cachedAdminToken === null) {
            $user = LandlordUser::query()->find($this->landlord->user_superadmin->user_id)
                ?? LandlordUser::query()->first();
            $this->cachedAdminToken = $user
                ? $user->createToken('Test Token', AbilityCatalog::all())->plainTextToken
                : $this->landlord->user_superadmin->token;
        }

        return [
            'Authorization' => "Bearer {$this->cachedAdminToken}",
            'Content-Type' => 'application/json',
        ];
    }
}
