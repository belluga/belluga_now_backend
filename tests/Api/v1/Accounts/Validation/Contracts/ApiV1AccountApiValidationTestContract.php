<?php

namespace Tests\Api\v1\Accounts\Validation\Contracts;

use App\Application\Accounts\AccountUserService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Support\Str;
use Tests\Api\Traits\AccountAuthFunctions;
use Illuminate\Testing\TestResponse;
use Tests\Api\Traits\AccountProfileFunctions;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountApiValidationTestContract extends TestCaseAccount
{
    use AccountAuthFunctions, AccountProfileFunctions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureValidationFixtures();
        $this->accountLogin($this->account->user_visitor);
    }

    protected string $base_api_url {
        get{
            return $this->base_api_account;
        }
    }

    public function testAccountRolesCreate(): void
    {

        $response = $this->accountRolesCreate();

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'name',
                'permissions',
            ],
        ]);

    }

    public function testAccountRolesUpdate(): void
    {

        $response = $this->accountRolesUpdate($this->account->role_visitor->id);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'permissions',
            ],
        ]);
    }

    public function testAccountRolesDelete(): void
    {
        $deleteResponse = $this->accountRolesDelete(
            $this->account->role_visitor->id,
            []
        );
        $deleteResponse->assertStatus(422);

        $deleteResponse->assertJsonStructure([
            'message',
            'errors' => [
                'background_role_id',
            ],
        ]);
    }

    public function testAccountUserCreate(): void
    {

        $response = $this->userCreate([]);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            'message',
            'errors' => [
                'name',
                'email',
                'password',
                'role_id',
            ],
        ]);
    }

    public function testAccountUserEmailRemove(): void
    {

        $response = $this->profileRemoveEmail(
            $this->account->user_visitor,
            '');

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'email',
            ],
        ]);
    }

    public function testAccountUserEmailAddEmpty(): void
    {

        $response = $this->profileAddEmails(
            $this->account->user_visitor,
            ''
        );
        $response->assertStatus(422);

        $response->assertJsonStructure([
            'message',
            'errors' => [
                'email',
            ],
        ]);

        $response = $this->profileAddEmails(
            $this->account->user_visitor,
            $this->account->user_visitor->email_2,
        );
        $response->assertStatus(200);
    }

    public function testAccountUserUpdate(): void
    {

        $response = $this->profileUpdate(
            $this->account->user_visitor,
            []);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            'message',
            'errors' => [
                'empty',
            ],
        ]);
    }

    protected function accountRolesCreate(): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}roles",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesUpdate(string $roleId): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesDelete(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userDelete(array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    private function ensureValidationFixtures(): void
    {
        $account = Account::query()->where('slug', $this->account->slug)->first();
        if (! $account instanceof Account) {
            return;
        }

        $role = $account->roleTemplates()
            ->withTrashed()
            ->where('name', 'Visitor')
            ->first();

        if (! $role instanceof AccountRoleTemplate) {
            $role = $account->roleTemplates()->create([
                'name' => 'Visitor',
                'permissions' => [],
            ]);
        } else {
            if ($role->trashed()) {
                $role->restore();
            }

            $role->permissions = [];
            $role->save();
        }

        $this->account->role_visitor->name = $role->name;
        $this->account->role_visitor->id = (string) $role->_id;

        $this->seedAccountUserFixture(
            $account,
            $this->account->user_visitor,
            $role,
            'Visitor',
            'visitor+'.Str::slug($account->slug).'.validation@example.org',
            'Secret!234',
        );
    }

    private function seedAccountUserFixture(
        Account $account,
        UserLabels $label,
        AccountRoleTemplate $role,
        string $name,
        string $email,
        string $password,
    ): void {
        /** @var AccountUserService $service */
        $service = app(AccountUserService::class);
        $user = $service->create(
            $account,
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ],
            (string) $role->_id,
        );

        $secondaryEmail = $label->email_2;
        if ($secondaryEmail === '') {
            $secondaryEmail = 'secondary+'.Str::uuid()->toString().'@example.org';
        }

        $label->name = $name;
        $label->email_1 = $email;
        $label->email_2 = $secondaryEmail;
        $label->password = $password;
        $label->user_id = (string) $user->_id;
    }
}
