<?php

namespace Tests\Api\Traits;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;

trait TenantAdminMiddlewareFixtureSeeder
{
    protected function resolveOrCreateTenant(TenantLabels $labels): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', $labels->subdomain)
            ->first();

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::create([
                'name' => $labels->name,
                'subdomain' => $labels->subdomain,
                'app_domains' => [],
            ]);
        }

        $labels->id = (string) $tenant->_id;
        $labels->slug = $tenant->slug;
        $labels->subdomain = $tenant->subdomain;

        return $tenant->fresh();
    }

    protected function seedTenantUsers(Tenant $tenant, TenantLabels $labels): void
    {
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_admin,
            name: 'Tenant Admin',
            emailLocalPart: 'tenant-admin',
            password: 'Secret!234',
            rolePermissions: ['account-roles:view', 'tenant-roles:view'],
            tokenAbilities: ['account-roles:view', 'tenant-roles:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_roles_manager,
            name: 'Tenant Roles Manager',
            emailLocalPart: 'tenant-roles-manager',
            password: 'Secret!234',
            rolePermissions: ['tenant-roles:view'],
            tokenAbilities: ['tenant-roles:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_users_manager,
            name: 'Tenant Users Manager',
            emailLocalPart: 'tenant-users-manager',
            password: 'Secret!234',
            rolePermissions: ['tenant-users:view'],
            tokenAbilities: ['tenant-users:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_visitor,
            name: 'Tenant Visitor',
            emailLocalPart: 'tenant-visitor',
            password: 'Secret!234',
            rolePermissions: [],
            tokenAbilities: [],
        );
    }

    protected function seedTenantUser(
        Tenant $tenant,
        UserLabels $label,
        string $name,
        string $emailLocalPart,
        string $password,
        array $rolePermissions,
        array $tokenAbilities,
    ): void {
        $email = sprintf('%s-%s@middleware.test', $tenant->subdomain, $emailLocalPart);
        $passwordHash = Hash::make($password);

        $user = LandlordUser::query()
            ->where('emails', 'all', [$email])
            ->first();

        if (! $user instanceof LandlordUser) {
            $user = LandlordUser::create([
                'name' => $name,
                'emails' => [$email],
                'identity_state' => 'registered',
            ]);
        }

        $user->name = $name;
        $user->emails = [$email];
        $user->identity_state = 'registered';
        $user->tenant_roles = [[
            'name' => $name,
            'slug' => Str::slug($name),
            'permissions' => $rolePermissions,
            'tenant_id' => (string) $tenant->_id,
        ]];
        $user->save();
        $user->syncCredential('password', $email, $passwordHash);

        LandlordUser::query()
            ->whereKey($user->getKey())
            ->update([
                'password' => $passwordHash,
                'password_type' => 'laravel',
            ]);

        $label->name = $name;
        $label->email_1 = $email;
        $label->email_2 = '';
        $label->password = $password;
        $label->password_reset_token = '';
        $label->user_id = (string) $user->_id;
        $label->token = $user->createToken(
            sprintf('middleware-%s', $emailLocalPart),
            $tokenAbilities,
        )->plainTextToken;
    }

    protected function seedAccountFixtures(Tenant $tenant, AccountLabels $labels): void
    {
        $tenant->makeCurrent();

        $seedKey = $this->labelSeedKey($labels);
        $account = Account::create([
            'name' => sprintf('%s %s', Str::afterLast(static::class, '\\'), $seedKey),
            'document' => strtoupper(substr(md5(static::class.$seedKey), 0, 14)),
        ]);

        $labels->id = (string) $account->_id;
        $labels->name = $account->name;
        $labels->document = $account->document;
        $labels->slug = $account->slug;

        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_admin,
            name: 'Account Admin',
            emailLocalPart: 'account-admin',
            password: 'Secret!234',
            permissions: ['account-roles:view'],
        );
        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_users_manager,
            name: 'Account Users Manager',
            emailLocalPart: 'account-users-manager',
            password: 'Secret!234',
            permissions: ['account-users:view'],
        );
        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_visitor,
            name: 'Account Visitor',
            emailLocalPart: 'account-visitor',
            password: 'Secret!234',
            permissions: [],
        );
    }

    protected function seedAccountUser(
        Tenant $tenant,
        Account $account,
        UserLabels $label,
        string $name,
        string $emailLocalPart,
        string $password,
        array $permissions,
    ): void {
        $tenant->makeCurrent();
        $account->makeCurrent();

        $email = sprintf('%s-%s@middleware.test', $account->slug, $emailLocalPart);
        $user = $account->users()->create([
            'name' => $name,
            'emails' => [$email],
            'password' => $password,
            'identity_state' => 'registered',
        ]);

        $user->account_roles = [[
            'account_id' => (string) $account->_id,
            'permissions' => $permissions,
            'name' => $name,
        ]];
        $user->save();

        $label->name = $name;
        $label->email_1 = $email;
        $label->email_2 = '';
        $label->password = $password;
        $label->password_reset_token = '';
        $label->user_id = (string) $user->_id;
        $label->token = $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser(
                $user,
                sprintf('middleware-%s', $emailLocalPart),
                $permissions,
                accountId: (string) $account->_id,
            )
            ->plainTextToken;
    }

    protected function labelSeedKey(AccountLabels $label): string
    {
        $property = new \ReflectionProperty($label, 'base_label');
        $property->setAccessible(true);

        return (string) $property->getValue($label);
    }
}
