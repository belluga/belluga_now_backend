<?php

namespace Tests\Api\default\Admin;

use Illuminate\Support\Facades\DB;
use Tests\Api\default\Admin\Traits\AdminAuthFunctions;
use Tests\Api\Traits\AdminProfileFunctions;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminProfileTest extends TestCaseAuthenticated {

    use AdminProfileFunctions, AdminAuthFunctions;
    protected string $base_api_url {
        get{
            return "admin/api/";
        }
    }

    private string $temporary_email_1 = "temporaryemail1@gmail.com";

    private string $temporary_email_2 = "temporaryemail2@gmail.com";

    private string $temporary_phone_1 = "5531996419823";

    private string $temporary_phone_2 = "5533999999999";

    public function testTokenGenerate(): void {
        $response = $this->generateToken($this->landlord->user_cross_tenant_admin->email_1);
        $response->assertOk();

        $token = DB::connection('landlord')->table('password_reset_tokens')->where('user_id', $this->landlord->user_cross_tenant_admin->user_id)->first();

        $this->assertNotNull($token);
        $this->assertEquals($this->landlord->user_cross_tenant_admin->user_id, $token->user_id);;

        $this->landlord->user_cross_tenant_admin->password_reset_token = $token->token;

    }

    public function testResetPasswordTokenInvalid(): void {

        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->resetPassword(
            email: $this->landlord->user_cross_tenant_admin->email_1,
            password: $this->landlord->user_cross_tenant_admin->password,
            password_confirmation: $this->landlord->user_cross_tenant_admin->password,
            reset_token: '123456',
        );
        $response->assertStatus(422);
    }

    public function testTokenResetPasswordSuccess(): void {

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

    public function testUpdatePassword(): void {

        $this->adminLogin($this->landlord->user_cross_tenant_admin);

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

    public function testUpdateProfile(): void {

        $this->adminLogin($this->landlord->user_cross_tenant_admin);

        $this->landlord->user_cross_tenant_admin->name = fake()->name()." Name Created Updating Profile";

        $response = $this->profileUpdate(
            user: $this->landlord->user_cross_tenant_admin,
            data:[
                "name" => $this->landlord->user_cross_tenant_admin->name,
            ]
        );
        $response->assertOk();

        $this->assertEquals($this->landlord->user_cross_tenant_admin->name, $response->json()['name']);;

    }

    public function testAddEmails(): void {

        $userUpdate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_email_1,
                $this->temporary_email_2,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains($this->temporary_email_1, $userUpdate->json()['data']['emails']);
        $this->assertContains($this->temporary_email_2, $userUpdate->json()['data']['emails']);
    }

    public function testAddEmailsRepeated(): void {

        $this->adminLogin($this->landlord->user_cross_tenant_visitor);

        $userUpdate = $this->profileAddEmails(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_email_1,
            ]
        );

        $userUpdate->assertStatus(422);

        $userUpdate ->assertJsonStructure([
            "errors" => [
                "emails"
            ]
        ]);
    }

    public function testRemoveEmail(): void {
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

    public function testAddPhones(): void {

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_admin,
            [
                $this->temporary_phone_1,
                $this->temporary_phone_2,
            ]
        );

        $userUpdate->assertStatus(200);

        $this->assertContains($this->temporary_phone_1, $userUpdate->json()['data']['phones']);
        $this->assertContains($this->temporary_phone_2, $userUpdate->json()['data']['phones']);
    }

    public function testAddPhonesRepeated(): void {

        $this->adminLogin($this->landlord->user_cross_tenant_visitor);

        $userUpdate = $this->profileAddPhones(
            $this->landlord->user_cross_tenant_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $userUpdate->assertStatus(422);

        $userUpdate ->assertJsonStructure([
            "errors" => [
                "phones"
            ]
        ]);
    }

    public function testRemovePhones(): void {
        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_phone_1,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_phone_1, $userUpdate->json()['data']['phones']);


        $userUpdate = $this->profileRemovePhone(
            $this->landlord->user_cross_tenant_admin,
            $this->temporary_phone_2,
        );
        $userUpdate->assertStatus(200);

        $this->assertNotContains($this->temporary_phone_2, $userUpdate->json()['data']['phones']);
    }
}
