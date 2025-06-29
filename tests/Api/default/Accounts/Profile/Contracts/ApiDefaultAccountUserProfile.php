<?php

namespace Tests\Api\default\Accounts\Profile\Contracts;

use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Api\Traits\AccountProfileFunctions;
use Tests\TestCaseAccount;

abstract class ApiDefaultAccountUserProfile extends TestCaseAccount{

    use AccountProfileFunctions, AccountAuthFunctions;

    private string $temporary_email_1 = "temporaryemail1@gmail.com";

    private string $temporary_email_2 = "temporaryemail2@gmail.com";

    private string $temporary_phone_1 = "5531996419823";

    private string $temporary_phone_2 = "5533999999999";

    protected string $base_api_url {
        get{
            return "http://{$this->tenant->subdomain}.localhost/api/";
        }
    }

    public function testAccountUserUpdate(): void {

        $this->accountLogin($this->account->user_visitor);

        $roleUpdate = $this->profileUpdate(
            $this->account->user_visitor,
            [
                "name" => "Updated Account Name",
            ]
        );

        $roleUpdate->assertStatus(200);

        $this->assertEquals("Updated Account Name", $roleUpdate->json()['name']);

    }

    public function testAccountUserAddEmail(): void {

        $roleUpdate = $this->profileAddEmails(
            $this->account->user_visitor,
            [
                $this->temporary_email_1,
                $this->temporary_email_2,
            ]
        );

        $roleUpdate->assertStatus(200);

        $this->assertContains($this->temporary_email_1, $roleUpdate->json()['data']['emails']);
        $this->assertContains($this->temporary_email_2, $roleUpdate->json()['data']['emails']);
    }

    public function testAccountUserAddEmailRepeated(): void {

        $this->accountLogin($this->account->user_users_manager);

        $userUpdate = $this->profileAddEmails(
            $this->account->user_users_manager,
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

    public function testAccountUserRemoveEmail(): void
    {

        $addEmailsResponse = $this->profileRemoveEmail(
            $this->account->user_visitor,
            $this->temporary_email_1
        );

        $addEmailsResponse->assertStatus(200);

        $addEmailsResponse = $this->profileRemoveEmail(
            $this->account->user_visitor,
            $this->temporary_email_2
        );

        $addEmailsResponse->assertStatus(200);

        $addEmailsResponse = $this->profileRemoveEmail(
            $this->account->user_visitor,
            $this->account->user_visitor->email_2
        );

        $addEmailsResponse->assertStatus(200);

        $this->assertNotContains($this->account->user_visitor->email_2, $addEmailsResponse->json()['data']['emails']);
        $this->assertContains($this->account->user_visitor->email_1, $addEmailsResponse->json()['data']['emails']);

        $addEmailsResponse = $this->profileRemoveEmail(
            $this->account->user_visitor,
            $this->account->user_visitor->email_1
        );

        $addEmailsResponse->assertStatus(422);

        $this->assertEquals(
            "Você não pode remover o único email da conta. Adicione outro email antes de remover esse.",
            $addEmailsResponse->json()['message']);

        $addEmailsResponse->assertJsonStructure([
            "message",
            "errors" => [
                "email"
            ]
        ]);
    }

    public function testAccountUserAddPhones(): void {

        $update = $this->profileAddPhones(
            $this->account->user_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $update->assertStatus(200);
        $this->assertContains($this->temporary_phone_1, $update->json()['data']['phones']);


        $update = $this->profileAddPhones(
            $this->account->user_users_manager,
            [
                $this->temporary_phone_2,
            ]
        );

        $update->assertStatus(200);
        $this->assertContains($this->temporary_phone_2, $update->json()['data']['phones']);
    }

    public function testAccountUserAddPhoneRepeated(): void {

        $this->accountLogin($this->account->user_users_manager);

        $update = $this->profileAddPhones(
            $this->account->user_users_manager,
            [
                $this->temporary_phone_1,
            ]
        );

        $update->assertStatus(422);

        $update ->assertJsonStructure([
            "errors" => [
                "phones"
            ]
        ]);
    }

    public function testAccountUserRemovePhone(): void
    {

        $response = $this->profileRemovePhone(
            $this->account->user_visitor,
            $this->temporary_phone_1
        );

        $response->assertStatus(200);

        $response = $this->profileRemovePhone(
            $this->account->user_users_manager,
            $this->temporary_phone_2
        );

        $response->assertStatus(200);

        $this->assertNotContains($this->temporary_phone_1, $response->json()['data']['phones']);
        $this->assertNotContains($this->temporary_phone_2, $response->json()['data']['phones']);
    }
}
