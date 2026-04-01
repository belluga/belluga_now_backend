<?php

namespace Tests\Api\v1\Admin;

use App\Support\Helpers\PhoneNumberParser;
use Illuminate\Support\Facades\DB;
use Tests\Api\Traits\AdminAuthFunctions;
use Tests\Api\Traits\AdminProfileFunctions;
use Tests\TestCaseAuthenticated;

class ApiV1AdminProfileTest extends TestCaseAuthenticated
{
    use AdminAuthFunctions, AdminProfileFunctions;

    protected string $base_api_url {
        get{
            return 'admin/api/v1/';
        }
    }

    private string $temporary_email_1 = 'temporaryemail1@gmail.com';

    private string $temporary_email_2 = 'temporaryemail2@gmail.com';

    private string $temporary_phone_1 = '5531996419823';

    private string $temporary_phone_2 = '27996419823';

    public function test_token_generate(): void
    {
        $response = $this->generateToken($this->landlord->user_cross_tenant_admin->email_1);
        $response->assertOk();

        $token = DB::connection('landlord')->table('password_reset_tokens')->where('user_id', $this->landlord->user_cross_tenant_admin->user_id)->first();

        $this->assertNotNull($token);
        $this->assertEquals($this->landlord->user_cross_tenant_admin->user_id, $token->user_id);

        $this->landlord->user_cross_tenant_admin->password_reset_token = $token->token;

    }

    public function test_reset_password_token_invalid(): void
    {

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: '123456',
        );
        $response->assertStatus(422);
    }

    public function test_token_reset_password_success(): void
    {

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: $this->landlord->user_cross_tenant_admin->password_reset_token
        );
        $response->assertOk();

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

    }

    public function test_update_password(): void
    {

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->passwordUpdate(
            user: $this->landlord->user_cross_tenant_admin,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password
        );
        $response->assertOk();

        $this->adminLogout($this->landlord->user_cross_tenant_admin);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();

    }

    public function test_login_users(): void
    {
        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertOk();

        $response = $this->adminLogin($this->landlord->user_cross_tenant_visitor);
        $response->assertOk();
    }

    public function test_update_profile(): void
    {

        $this->landlord->user_cross_tenant_admin->name = fake()->name().' Name Created Updating Profile';

        $response = $this->profileUpdate(
            user: $this->landlord->user_cross_tenant_admin,
            data: [
                'name' => $this->landlord->user_cross_tenant_admin->name,
            ]
        );
        $response->assertOk();

        $this->assertEquals($this->landlord->user_cross_tenant_admin->name, $response->json()['name']);

    }

    public function test_add_emails(): void
    {

        $firstResponse = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );

        $firstResponse->assertStatus(200);
        $this->assertContains($this->temporary_email_1, $firstResponse->json()['data']['emails']);

        $secondResponse = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        );

        $secondResponse->assertStatus(200);
        $this->assertContains($this->temporary_email_2, $secondResponse->json()['data']['emails']);
    }

    public function test_add_emails_repeated(): void
    {
        $seedDuplicate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );
        $seedDuplicate->assertStatus(200);

        $userUpdate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_visitor,
            $this->temporary_email_1,
        );

        $userUpdate->assertStatus(422);

        $userUpdate->assertJsonStructure([
            'errors' => [
                'email',
            ],
        ]);
    }

    public function test_remove_email(): void
    {
        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        )->assertStatus(200);

        $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        )->assertStatus(200);

        $userUpdate = $this->profileRemoveEmail(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_1,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_email_1, $userUpdate->json()['data']['emails']);

        $userUpdate = $this->profileRemoveEmail(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_email_2,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_email_2, $userUpdate->json()['data']['emails']);
    }

    public function test_add_phone_to_first_user(): void
    {

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_phone_1,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
    }

    public function test_add_phone_to_second_user(): void
    {

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_2,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_2), $userUpdate->json()['data']['phones']);
        $this->assertCount(1, $userUpdate->json()['data']['phones']);

    }

    public function test_add_phones_repeated(): void
    {
        $firstUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_1,
            ]
        );
        $firstUpdate->assertStatus(200);

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $userUpdate->assertStatus(200);
        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
        $this->assertCount(1, $userUpdate->json()['data']['phones']);
    }

    public function test_remove_phone_from_firs_user(): void
    {
        $this->profileAddPhones(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_phone_1,
            ]
        )->assertStatus(200);

        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_phone_1,
        );

        $userUpdate->assertStatus(200);

        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_1), $userUpdate->json()['data']['phones']);
    }

    public function test_remove_phone_from_second_user(): void
    {
        $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_2,
            ]
        )->assertStatus(200);

        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_visitor,
            $this->temporary_phone_2,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_2), $userUpdate->json()['data']['phones']);
    }
}
