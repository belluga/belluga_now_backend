<?php

namespace Tests\Api\default\Accounts\Users\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Contracts\TestCaseAccount;
use Tests\Helpers\RoleLabels;
use Tests\Helpers\UserLabels;

abstract class ApiDefaultAccountUsersManageTestContract extends TestCaseAccount
{
    protected string $base_api_url {
        get{
            return $this->base_api."users/";
        }
    }

    private string $temporary_email_1 = "temporaryemail1@gmail.com";

    private string $temporary_email_2 = "temporaryemail2@gmail.com";

    public function testAccountUserAdminCreation(): void {
        $response = $this->createUser(
            $this->account->user_admin,
            $this->account->role_admin
        );
        $response->assertStatus(201);
    }

    public function testAccountUserUserManagerCreation(): void {
        $response = $this->createUser(
            $this->account->user_users_manager,
            $this->account->role_user_manager
        );
        $response->assertStatus(201);
    }

    public function testAccountUserVisitorCreation(): void {
        $response = $this->createUser(
            $this->account->user_visitor,
            $this->account->role_visitor
        );
        $response->assertStatus(201);
    }

    public function testAccountUserDisposableCreation(): void {
        $response = $this->createUser(
            $this->account->user_disposable,
            $this->account->role_visitor
        );
        $response->assertStatus(201);
    }

    public function testAccountUserDetach(): void {
        $responseShow = $this->accountUserShow($this->account->user_disposable->user_id);
        $responseShow->assertStatus(200);

        $responseDelete = $this->accountUserDelete($this->account->user_disposable->user_id);
        $responseDelete->assertStatus(200);

        $responseShowTenant2 = $this->accountUserShow($this->account->user_disposable->user_id);
        $responseShowTenant2->assertStatus(404);
    }

    public function testAttachExistentUserToAccount():void {

        $response = $this->accountUserCreate([
            "name" => $this->account->user_disposable->name,
            "emails" => [
                $this->account->user_disposable->email_1,
            ],
            "password" => $this->account->user_disposable->password,
            "password_confirmation" => $this->account->user_disposable->password,
            "role_id" => $this->account->role_visitor->id,
        ]);

        $response->assertStatus(201);

        $responseShow = $this->accountUserShow($this->account->user_disposable->user_id);

        $responseShow->assertStatus(200);
    }

    public function testAccountUsersList(): void {

        $accountUserList = $this->accountUsersList();
        $accountUserList->assertStatus(200);

        $this->assertArrayHasKey('total', $accountUserList->json());
        $this->equalTo(4, $accountUserList->json()['total']);

    }

    public function testAccountUserUpdate(): void {
        $roleUpdate = $this->accountUserUpdate(
            $this->account->user_disposable->user_id,
            [
                "name" => "Updated Account Name",
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountUserShow($this->account->user_disposable->user_id);
        $rolesShow->assertOk();

        $this->assertEquals("Updated Account Name", $rolesShow->json()['data']['name']);

    }

    public function testAccountUserAddEmail(): void {

        $roleUpdate = $this->accountUserAddEmails(
            $this->account->user_disposable->user_id,
            [
                "emails" => [
                    $this->temporary_email_1,
                    $this->temporary_email_2,
                ],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountUserShow($this->account->user_disposable->user_id);
        $rolesShow->assertOk();

        $this->assertCount(4, $rolesShow->json()['data']['emails']);
        $this->assertEquals($this->temporary_email_1, $rolesShow->json()['data']['emails'][2]);
        $this->assertEquals($this->temporary_email_2, $rolesShow->json()['data']['emails'][3]);
    }

    public function testAccountUserRemoveEmail(): void
    {

        $addEmailsResponse = $this->accountUserRemoveEmails(
            $this->account->user_disposable->user_id,
            [
                "email" => $this->temporary_email_1
            ]
        );

        $addEmailsResponse->assertStatus(200);

        $addEmailsResponse = $this->accountUserRemoveEmails(
            $this->account->user_disposable->user_id,
            [
                "email" => $this->temporary_email_2
            ]
        );

        $addEmailsResponse->assertStatus(200);

        $addEmailsResponse = $this->accountUserRemoveEmails(
            $this->account->user_disposable->user_id,
            [
                "email" => $this->account->user_disposable->email_2
            ]
        );

        $addEmailsResponse->assertStatus(200);

        $accountUserShow = $this->accountUserShow($this->account->user_disposable->user_id);
        $accountUserShow->assertOk();

        $this->assertCount(1, $accountUserShow->json()['data']['emails']);
        $this->assertNotContains($this->account->user_disposable->email_2, $accountUserShow->json()['data']['emails']);
        $this->assertContains($this->account->user_disposable->email_1, $accountUserShow->json()['data']['emails']);

        $addEmailsResponse = $this->accountUserRemoveEmails(
            $this->account->user_disposable->user_id,
            [
                "email" => $this->account->user_disposable->email_1
            ]
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

    public function testAccountDetachOrDelete(): void {
        $rolesList = $this->accountUsersList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(4, $responseData['total']);

        $deleteResponse = $this->accountUserDelete($this->account->user_disposable->user_id);
        $deleteResponse->assertStatus(200);

        $rolesList = $this->accountUsersList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(3, $responseData['total']);

        $rolesList = $this->accountUsersListArchived();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(0, $responseData['total']);

        $showDeleted = $this->accountUserShow($this->account->user_disposable->user_id);
        $showDeleted->assertStatus(404);
    }

    protected function createUser(UserLabels $user, RoleLabels $role): TestResponse {
        $user->name = fake()->name();
        $user->email_1 = fake()->email();
        $user->email_2 = fake()->email();
        $user->password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $user->name,
            "emails" => [
                $user->email_1,
                $user->email_2,
            ],
            "password" => $user->password,
            "password_confirmation" => $user->password,
            "role_id" => $role->id,

        ]);

        $response->assertStatus(201);

        $user->user_id = $response->json()['data']["id"];

        return $response;
    }

    protected function accountUserCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserCreateSecondaryAccount(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserCreateMainTenant(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUsersList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUsersListArchived(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserShowSecondaryAccount(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserUpdate(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}$user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserAddEmails(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserRemoveEmails(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserRestore(string $user_id): TestResponse {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}$user_id/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserForceDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}$user_id/force_delete",
            headers: $this->getHeaders(),
        );
    }
}
