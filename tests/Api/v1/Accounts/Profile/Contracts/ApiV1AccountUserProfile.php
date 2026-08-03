<?php

namespace Tests\Api\v1\Accounts\Profile\Contracts;

use App\Application\Accounts\AccountUserService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use Illuminate\Support\Str;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Api\Traits\AccountProfileFunctions;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountUserProfile extends TestCaseAccount
{
    use AccountAuthFunctions, AccountProfileFunctions;

    private string $temporary_email_1 = 'temporaryemail1@gmail.com';

    private string $temporary_email_2 = 'temporaryemail2@gmail.com';

    private string $temporary_phone_1 = '5531996419823';

    private string $temporary_phone_2 = '5533999999999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAccountProfileFixtures();
    }

    protected string $base_api_url {
        get{
            return "http://{$this->tenant->subdomain}.".env('APP_HOST').'/api/v1/';
        }
    }

    public function testAccountUserUpdate(): void
    {

        $this->accountLogin($this->account->user_visitor);

        $roleUpdate = $this->profileUpdate(
            $this->account->user_visitor,
            [
                'name' => 'Updated Account Name',
            ]
        );

        $roleUpdate->assertStatus(200);

        $this->assertEquals('Updated Account Name', $roleUpdate->json()['name']);

    }

    public function testAccountUserAddEmail(): void
    {

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

    public function testAccountUserAddEmailRepeated(): void
    {

        $this->accountLogin($this->account->user_users_manager);

        $userUpdate = $this->profileAddEmails(
            $this->account->user_users_manager,
            $this->temporary_email_1,
        );

        $userUpdate->assertStatus(422);

        $userUpdate->assertJsonStructure([
            'errors' => [
                'email',
            ],
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
            'Você não pode remover o único email da conta. Adicione outro email antes de remover esse.',
            $addEmailsResponse->json()['message']);

        $addEmailsResponse->assertJsonStructure([
            'message',
            'errors' => [
                'email',
            ],
        ]);
    }

    public function testAccountUserAddPhonesFirstUserIsRejected(): void
    {

        $update = $this->profileAddPhones(
            $this->account->user_visitor,
            [
                $this->temporary_phone_1,
            ]
        );

        $update->assertStatus(422);
        $update->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
    }

    public function testAccountUserAddPhonesSecondUserIsRejected(): void
    {

        $update = $this->profileAddPhones(
            $this->account->user_users_manager,
            [
                $this->temporary_phone_2,
            ]
        );

        $update->assertStatus(422);
        $update->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
    }

    public function testAccountUserAddPhoneRepeatedIsRejected(): void
    {

        $this->accountLogin($this->account->user_users_manager);

        $update = $this->profileAddPhones(
            $this->account->user_users_manager,
            [
                $this->temporary_phone_1,
            ]
        );

        $update->assertStatus(422);

        $update->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
    }

    public function testAccountUserRemovePhoneFirstUserIsRejected(): void
    {

        $response = $this->profileRemovePhone(
            $this->account->user_visitor,
            $this->temporary_phone_1
        );

        $response->assertStatus(422);
        $response->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
    }

    public function testAccountUserRemovePhoneSecondUserIsRejected(): void
    {
        $response = $this->profileRemovePhone(
            $this->account->user_users_manager,
            $this->temporary_phone_2
        );

        $response->assertStatus(422);
        $response->assertJsonPath(
            'errors.phone.0',
            'Telefone verificado não pode ser alterado por este endpoint.',
        );
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

    private function ensureAccountProfileFixtures(): void
    {
        $account = Account::query()->where('slug', $this->account->slug)->first();
        if (! $account instanceof Account) {
            return;
        }

        $roles = [
            'role_user_manager' => $this->ensureAccountRole(
                $account,
                'Users Manager',
                ['account-users:view', 'account-users:create']
            ),
            'role_visitor' => $this->ensureAccountRole(
                $account,
                'Visitor',
                []
            ),
        ];

        $this->account->role_user_manager->name = $roles['role_user_manager']->name;
        $this->account->role_user_manager->id = (string) $roles['role_user_manager']->_id;
        $this->account->role_visitor->name = $roles['role_visitor']->name;
        $this->account->role_visitor->id = (string) $roles['role_visitor']->_id;

        $this->seedAccountUserFixture(
            $account,
            $this->account->user_users_manager,
            $roles['role_user_manager'],
            'Users Manager',
            'users-manager+'.$account->slug.'@example.org',
            'Secret!234',
        );

        $this->seedAccountUserFixture(
            $account,
            $this->account->user_visitor,
            $roles['role_visitor'],
            'Visitor',
            'visitor+'.$account->slug.'@example.org',
            'Secret!234',
        );
    }

    private function ensureAccountRole(Account $account, string $name, array $permissions): AccountRoleTemplate
    {
        /** @var AccountRoleTemplate|null $role */
        $role = $account->roleTemplates()
            ->withTrashed()
            ->where('name', $name)
            ->first();

        if (! $role instanceof AccountRoleTemplate) {
            /** @var AccountRoleTemplate $created */
            $created = $account->roleTemplates()->create([
                'name' => $name,
                'permissions' => $permissions,
            ]);

            return $created;
        }

        if ($role->trashed()) {
            $role->restore();
        }

        $role->permissions = $permissions;
        $role->save();

        return $role;
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
