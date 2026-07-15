<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Application\Profiles\CurrentTenantAccountDeletionAccountGuard;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\TenantSettings;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class CurrentTenantAccountDeletionConcurrencyTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected static bool $bootstrapped = false;

    private Tenant $tenantModel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantModel = Tenant::query()->firstOrFail();
        $this->tenantModel->makeCurrent();
    }

    private function initializeSystem(): void
    {
        $this->ensureSystemInitialized();
    }

    public function test_deleted_phone_can_verify_into_a_clean_unrelated_identity_after_direct_delete(): void
    {
        $phone = '+5527999990304';
        $deletedUser = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deleted Identity',
            'phones' => [$phone],
            'credentials' => [],
        ]);
        $deletedUserId = (string) $deletedUser->_id;

        Sanctum::actingAs($deletedUser, ['*']);
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $this->configurePhoneOtpReviewAccess($phone);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'u05-clean-reregistration',
        ])->assertStatus(202);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => $phone,
            'code' => '123456',
            'device_name' => 'u05-clean-reregistration',
        ])->assertOk();

        $this->assertNotSame($deletedUserId, (string) $verify->json('data.user_id'));
        $this->assertNull(AccountUser::withTrashed()->find($deletedUserId));
        $this->assertSame(PhoneOtpChallenge::STATUS_VERIFIED, (string) PhoneOtpChallenge::query()
            ->findOrFail($challenge->json('data.challenge_id'))
            ->status);
    }

    public function test_stale_personal_account_snapshot_cannot_hard_delete_an_account_that_gained_a_member(): void
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deletion target',
            'phones' => ['+5527999990311'],
        ]);
        $other = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Concurrent member',
            'phones' => ['+5527999990312'],
        ]);
        $targetId = (string) $target->_id;
        $account = Account::create([
            'name' => 'Stale delete candidate',
            'slug' => 'stale-delete-candidate',
            'document' => ['type' => 'cpf', 'number' => 'STALE-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Deletion target',
            'slug' => 'stale-delete-profile',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $staleProfileIds = [(string) $profile->_id];
        $staleAccountIds = [(string) $account->_id];
        $other->account_roles = [[
            'account_id' => (string) $account->_id,
            'name' => 'Member',
            'slug' => 'member',
            'permissions' => [],
        ]];
        $other->save();

        app(CurrentTenantAccountDeletionAccountGuard::class)->eraseRevalidatedPersonalGraph(
            $targetId,
            $staleProfileIds,
            $staleAccountIds,
        );

        $this->assertNotNull(Account::query()->find((string) $account->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $profile->_id));
    }

    public function test_stale_personal_account_snapshot_cannot_hard_delete_an_account_that_gained_a_profile(): void
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Deletion target profile',
            'phones' => ['+5527999990313'],
        ]);
        $targetId = (string) $target->_id;
        $account = Account::create([
            'name' => 'Stale profile candidate',
            'slug' => 'stale-profile-candidate',
            'document' => ['type' => 'cpf', 'number' => 'STALE-PROFILE-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Deletion target profile',
            'slug' => 'stale-profile-delete-profile',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $profile->account_id = 'concurrently-reassigned-account';
        $profile->save();

        app(CurrentTenantAccountDeletionAccountGuard::class)->eraseRevalidatedPersonalGraph(
            $targetId,
            [(string) $profile->_id],
            [(string) $account->_id],
        );

        $this->assertNotNull(Account::query()->find((string) $account->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $profile->_id));
    }

    private function configurePhoneOtpReviewAccess(string $phone): void
    {
        $settings = TenantSettings::current() ?? new TenantSettings;
        $settings->setAttribute('_id', TenantSettings::ROOT_ID);
        $settings->tenant_public_auth = ['enabled_methods' => ['phone_otp']];
        $settings->phone_otp_review_access = [
            'phone_e164' => $phone,
            'code_hash' => app(\App\Application\Auth\PhoneOtpReviewAccessCodeHasher::class)->make('123456'),
        ];
        $settings->outbound_integrations = [
            'otp' => [
                'ttl_minutes' => 30,
                'resend_cooldown_seconds' => 1,
                'max_attempts' => 5,
            ],
        ];
        $settings->save();
    }
}
