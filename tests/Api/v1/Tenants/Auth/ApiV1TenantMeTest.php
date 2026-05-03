<?php

namespace Tests\Api\v1\Tenants\Auth;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Support\Helpers\PhoneNumberParser;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1TenantMeTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    public function test_tenant_me_returns_profile_payload(): void
    {
        $email = fake()->unique()->safeEmail();
        $password = 'Secret!234';

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/register/password",
            data: [
                'name' => 'Tenant Me User',
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'device_name' => 'tenant-me-register',
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-me-test',
            ]
        );

        $login->assertStatus(200);
        $token = $login->json('data.token');

        $response = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenant_id',
            'data' => [
                'user_id',
                'account_profile_id',
                'display_name',
                'avatar_url',
                'bio',
                'phone',
                'user_level',
                'privacy_mode',
                'social_score' => [
                    'invites_accepted',
                    'presences_confirmed',
                    'rank_label',
                ],
                'counters' => [
                    'pending_invites',
                    'confirmed_events',
                    'favorites',
                ],
                'role_claims' => [
                    'is_partner',
                    'is_curator',
                    'is_verified',
                ],
            ],
        ]);
        $response->assertJsonPath('data.user_level', 'basic');
        $response->assertJsonPath('data.privacy_mode', 'public');
        $this->assertNotEmpty($response->json('data.account_profile_id'));
    }

    public function test_tenant_profile_update_persists_personal_profile_and_me_readback(): void
    {
        $user = AccountUser::query()->create([
            'name' => 'Original Name',
            'emails' => [fake()->unique()->safeEmail()],
            'phones' => [PhoneNumberParser::parse('+55 27 99999-0042')],
            'identity_state' => 'registered',
        ]);
        $token = $user->createToken('tenant-profile-test')->plainTextToken;

        $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile",
            data: [
                'name' => 'Persisted Name',
                'bio' => 'Bio persisted through profile endpoint.',
            ],
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        )->assertStatus(200);

        $profile = AccountProfile::query()
            ->where('created_by', (string) $user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->where('deleted_at', null)
            ->first();

        $this->assertInstanceOf(AccountProfile::class, $profile);
        $this->assertSame('Persisted Name', $profile->display_name);
        $this->assertSame(
            'Bio persisted through profile endpoint.',
            trim(strip_tags((string) $profile->bio)),
        );

        $me = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );

        $me->assertStatus(200);
        $me->assertJsonPath('data.account_profile_id', (string) $profile->_id);
        $me->assertJsonPath('data.display_name', 'Persisted Name');
        $me->assertJsonPath('data.bio', 'Bio persisted through profile endpoint.');
        $me->assertJsonPath('data.phone', PhoneNumberParser::parse('+55 27 99999-0042'));
        $this->assertNotEmpty($me->json('data.account_profile_id'));
    }

    public function test_tenant_profile_phone_mutation_endpoints_are_rejected(): void
    {
        $user = AccountUser::query()->create([
            'name' => 'Phone Locked User',
            'emails' => [fake()->unique()->safeEmail()],
            'phones' => [PhoneNumberParser::parse('+55 27 99999-0099')],
            'identity_state' => 'registered',
        ]);
        $token = $user->createToken('tenant-profile-test')->plainTextToken;

        $add = $this->json(
            method: 'patch',
            uri: "{$this->base_api_tenant}profile/phones",
            data: [
                'phones' => ['+55 27 99999-1234'],
            ],
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );
        $add->assertStatus(422);
        $add->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );

        $remove = $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}profile/phones",
            data: [
                'phone' => '+55 27 99999-0099',
            ],
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );
        $remove->assertStatus(422);
        $remove->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
    }

    public function test_tenant_me_does_not_expose_phone_number_as_display_name_fallback(): void
    {
        $phone = PhoneNumberParser::parse('+55 27 99999-0011');
        $user = AccountUser::query()->create([
            'name' => $phone,
            'emails' => [fake()->unique()->safeEmail()],
            'phones' => [$phone],
            'identity_state' => 'registered',
        ]);
        $token = $user->createToken('tenant-profile-test')->plainTextToken;

        $response = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.display_name', '');
        $response->assertJsonPath('data.phone', $phone);
    }
}
