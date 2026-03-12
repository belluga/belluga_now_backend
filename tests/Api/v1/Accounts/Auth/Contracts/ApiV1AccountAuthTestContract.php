<?php

namespace Tests\Api\v1\Accounts\Auth\Contracts;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use Illuminate\Support\Str;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountAuthTestContract extends TestCaseAccount
{
    use AccountAuthFunctions;

    protected static array $seededAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $key = $this->accountSeedKey();

        if (! array_key_exists($key, static::$seededAccounts)) {
            $this->seedAccountAuthFixtures();
            static::$seededAccounts[$key] = true;
        }
    }

    public function testUserLoginWrongPassword(): void
    {

        $fake_user_label = new UserLabels('fake_user_label_wrong_password');
        $fake_user_label->email_1 = $this->account->user_visitor->email_1;
        $fake_user_label->password = fake()->password(8);

        $response = $this->accountLogin($fake_user_label);

        $response->assertStatus(403);

        $response->assertJsonStructure([
            'errors' => [
                'credentials',
            ],
        ]);
    }

    public function testUserLoginWrongEmail(): void
    {

        $fake_user_label = new UserLabels('fake_user_label_wrong_email');
        $fake_user_label->email_1 = fake()->email;
        $fake_user_label->password = $this->account->user_visitor->password;

        $response = $this->accountLogin($fake_user_label);

        $response->assertStatus(403);

        $response->assertJsonStructure([
            'errors' => [
                'credentials',
            ],
        ]);

    }

    public function testUserLoginLogoutManyDevicesSuccess(): void
    {

        $device_1 = 'device_1';
        $device_2 = 'device_2';

        $responseUserAdmin = $this->accountLogin(
            $this->account->user_visitor,
            $device_1);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            'data' => [
                'user',
                'token',
            ],
        ]);

        $responseUserAdmin = $this->accountLogin(
            $this->account->user_visitor,
            $device_2);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            'data' => [
                'user',
                'token',
            ],
        ]);

        $responseLogout = $this->accountLogout(
            user: $this->account->user_visitor,
            all_devices: true,
        );

        $responseLogout->assertStatus(200);
        $this->account->user_visitor->token = '';

    }

    public function testLogin(): void
    {

        $responseUserAdmin = $this->accountLogin($this->account->user_admin);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_admin->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin = $this->accountLogin($this->account->user_visitor);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];
    }

    public function testLoginWithToken(): void
    {
        $response = $this->accountTokenValidate($this->account->user_admin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
            ],
        ]);

        $response = $this->accountTokenValidate($this->account->user_visitor->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                'user',
            ],
        ]);
    }

    public function testLoginWithTokenError(): void
    {
        $response = $this->accountTokenValidate('123');
        $response->assertStatus(401);
    }

    public function testUserLoginRejectsPasswordExceedingMaxLength(): void
    {
        $user = new UserLabels('login_max_password');
        $user->email_1 = $this->account->user_admin->email_1;
        $user->password = str_repeat('A', 33);

        $response = $this->accountLogin($user);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password.0', 'The password field must not be greater than 32 characters.');
    }

    public function testUserLoginRejectsDeviceNameExceedingMaxLength(): void
    {
        $deviceName = str_repeat('d', 300);

        $response = $this->accountLogin($this->account->user_admin, $deviceName);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.device_name.0', 'The device name field must not be greater than 255 characters.');
    }

    private function seedAccountAuthFixtures(): void
    {
        $tenantLabel = $this->tenant;
        $tenant = Tenant::query()
            ->where('subdomain', $tenantLabel->subdomain)
            ->firstOrFail();
        $tenant->makeCurrent();

        $tenantLabel->id = (string) $tenant->_id;
        $tenantLabel->slug = $tenant->slug;

        $accountName = 'Account Auth '.Str::uuid()->toString();
        $account = Account::create([
            'name' => $accountName,
            'document' => strtoupper(Str::random(14)),
        ]);

        $this->account->id = (string) $account->_id;
        $this->account->name = $account->name;
        $this->account->document = $account->document;
        $this->account->slug = $account->slug;

        $this->seedAccountUser(
            $account,
            $this->account->user_admin,
            'Account Admin',
            'admin+'.$account->slug.'@example.org',
            'Secret!234',
            ['*']
        );

        $this->seedAccountUser(
            $account,
            $this->account->user_visitor,
            'Account Visitor',
            'visitor+'.$account->slug.'@example.org',
            'Secret!234',
            []
        );
    }

    private function accountSeedKey(): string
    {
        $label = $this->account;
        $ref = new \ReflectionProperty($label, 'base_label');
        $ref->setAccessible(true);

        return (string) $ref->getValue($label);
    }

    private function seedAccountUser(
        Account $account,
        UserLabels $label,
        string $name,
        string $email,
        string $password,
        array $permissions
    ): void {
        $user = $account->users()->create([
            'name' => $name,
            'emails' => [$email],
            'password' => $password,
            'identity_state' => 'registered',
        ]);

        $user->account_roles = [[
            'account_id' => (string) $account->_id,
            'permissions' => $permissions,
            'name' => 'Auth Seed',
        ]];
        $user->save();

        $label->name = $name;
        $label->email_1 = $email;
        $label->password = $password;
        $label->user_id = (string) $user->_id;
    }
}
