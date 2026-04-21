<?php

namespace Tests;

use App\Models\Landlord\LandlordUser;
use App\Support\Auth\AbilityCatalog;
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
