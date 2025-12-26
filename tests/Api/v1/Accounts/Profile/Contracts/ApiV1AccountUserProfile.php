<?php

namespace Tests\Api\v1\Accounts\Profile\Contracts;

use App\Support\Helpers\PhoneNumberParser;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Api\Traits\AccountProfileFunctions;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountUserProfile extends TestCaseAccount{

    use AccountProfileFunctions, AccountAuthFunctions;

    private string $temporary_email_1 = "temporaryemail1@gmail.com";

    private string $temporary_email_2 = "temporaryemail2@gmail.com";

    private string $temporary_phone_1 = "5531996419823";

    private string $temporary_phone_2 = "5533999999999";

    protected string $base_api_url {
        get{
            return "http://{$this->tenant->subdomain}.".env('APP_HOST')."/api/v1/";
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

        $firstUpdate = $this->profileAddEmails(
            $this->account->user_visitor,
            $this->temporary_email_1,
        );

        $firstUpdate->assertStatus(200);
        $this->assertContains($this->temporary_email_1, $firstUpdate->json()['data']['emails']);

        $secondUpdate = $this->profileAddEmails(
            $this->account->user_visitor,
            $this->temporary_email_2,
        );

        $secondUpdate->assertStatus(200);
        $this->assertContains($this->temporary_email_2, $secondUpdate->json()['data']['emails']);
    }

    public function testAccountUserAddEmailRepeated(): void {

        $this->accountLogin($this->account->user_users_manager);

        $userUpdate = $this->profileAddEmails(
            $this->account->user_users_manager,
            $this->temporary_email_1,
        );

        $userUpdate->assertStatus(422);

        $userUpdate ->assertJsonStructure([
            "errors" => [
                "email"
            ]
        ]);
    }

    public function testAccountUserRemoveEmail(): void
    {
        $this->accountLogin($this->account->user_visitor);

        $this->ensureEmailPresent($this->account->user_visitor, $this->temporary_email_1);
        $this->ensureEmailPresent($this->account->user_visitor, $this->temporary_email_2);

        $secondaryEmail = $this->account->user_visitor->email_2;
        if (empty($secondaryEmail)) {
            $secondaryEmail = fake()->unique()->safeEmail();
            $this->account->user_visitor->email_2 = $secondaryEmail;
        }
        $this->ensureEmailPresent($this->account->user_visitor, $secondaryEmail);
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
            $secondaryEmail
        );

        $addEmailsResponse->assertStatus(200);

        $this->assertNotContains($secondaryEmail, $addEmailsResponse->json()['data']['emails']);
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

    public function testAccountUserAddPhonesFirstUser(): void {

        $update = $this->profileAddPhones(
            $this->account->user_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $update->assertStatus(200);
        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_1), $update->json()['data']['phones']);
    }

    public function testAccountUserAddPhonesSecondUser(): void {

        $update = $this->profileAddPhones(
            $this->account->user_users_manager,
            [
                $this->temporary_phone_2,
            ]
        );

        $update->assertStatus(200);
        $this->assertContains(PhoneNumberParser::parse($this->temporary_phone_2), $update->json()['data']['phones']);
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

    public function testAccountUserRemovePhoneFirstUser(): void
    {

        $response = $this->profileRemovePhone(
            $this->account->user_visitor,
            $this->temporary_phone_1
        );

        $response->assertStatus(200);

        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_1), $response->json()['data']['phones']);
    }

    public function testAccountUserRemovePhoneSecondUser(): void
    {
        $response = $this->profileRemovePhone(
            $this->account->user_users_manager,
            $this->temporary_phone_2
        );

        $response->assertStatus(200);
        $this->assertNotContains(PhoneNumberParser::parse($this->temporary_phone_2), $response->json()['data']['phones']);
    }

    protected function ensureEmailPresent(UserLabels $user, string $email): void
    {
        $response = $this->profileAddEmails($user, $email);

        if ($response->status() === 200) {
            $this->assertContains(
                strtolower($email),
                array_map('strtolower', $response->json('data.emails') ?? [])
            );
            return;
        }

        $response->assertStatus(422);
    }
}
