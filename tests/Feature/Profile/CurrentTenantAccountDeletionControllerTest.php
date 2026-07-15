<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class CurrentTenantAccountDeletionControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->ensureSystemInitialized();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_registered_current_tenant_identity_can_permanently_delete_itself(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Delete Me',
            'phones' => ['+5527999990101'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('X-Api-Security-Domain', 'tenant_public_profile_delete');

        $this->assertNull(AccountUser::withTrashed()->find((string) $user->_id));
    }

    public function test_validated_current_tenant_identity_can_permanently_delete_itself(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'validated',
            'name' => 'Delete Me Too',
            'phones' => ['+5527999990103'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $this->assertNull(AccountUser::withTrashed()->find((string) $user->_id));
    }

    public function test_mobile_app_domain_query_is_routing_context_not_deletion_payload(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Delete Through Mobile Routing',
            'phones' => ['+5527999990199'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile?app_domain=com.example.mobile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $this->assertNull(AccountUser::withTrashed()->find((string) $user->_id));
    }

    public function test_unexpected_query_input_is_rejected_while_app_domain_remains_allowed(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Reject Query Command',
            'phones' => ['+5527999990198'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson(
            "{$this->base_api_tenant}profile?app_domain=com.example.mobile&target_user_id={$user->_id}",
            ['confirmation' => 'remove_account'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);

        $this->assertNotNull(AccountUser::query()->find((string) $user->_id));
    }

    public function test_anonymous_identity_is_rejected_before_any_deletion_mutation(): void
    {
        $anonymous = AccountUser::create([
            'identity_state' => 'anonymous',
            'fingerprints' => [['hash' => hash('sha256', 'delete-anonymous')]],
        ]);

        Sanctum::actingAs($anonymous, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertForbidden();

        $this->assertNotNull(AccountUser::query()->find((string) $anonymous->_id));
    }

    public function test_landlord_principal_is_rejected_before_any_deletion_mutation(): void
    {
        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertForbidden();
    }

    public function test_confirmation_is_exact_and_the_endpoint_accepts_no_target_user_input(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Keep Me',
            'phones' => ['+5527999990102'],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
            'target_user_id' => (string) $user->_id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);

        $this->assertNotNull(AccountUser::query()->find((string) $user->_id));

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'delete_everything',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);

        $this->assertNotNull(AccountUser::query()->find((string) $user->_id));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertUnauthorized();
    }
}
