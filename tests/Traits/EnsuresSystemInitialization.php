<?php

namespace Tests\Traits;

use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

trait EnsuresSystemInitialization
{
    protected static bool $systemInitialized = false;

    protected function ensureSystemInitialized(): void
    {
        $hasLandlordUser = LandlordUser::query()->exists();
        $hasTenant = Tenant::query()->exists();

        if ($hasLandlordUser && $hasTenant) {
            static::$systemInitialized = true;
            return;
        }

        static::$systemInitialized = false;

        if ($hasLandlordUser) {
            $this->hydrateFromDatabase();
            static::$systemInitialized = true;
            return;
        }

        $tenantHost = "{$this->landlord->tenant_primary->subdomain}.{$this->host}";
        $initializeUrl = "http://{$tenantHost}/api/v1/initialize";
        $response = $this->post(
            $initializeUrl,
            $this->initializationPayload(),
            ['Content-Type' => 'multipart/form-data']
        );

        $response->assertStatus(201);

        $data = $response->json('data');

        $this->landlord->user_superadmin->name = $data['user']['name'];
        $this->landlord->user_superadmin->email_1 = $data['user']['emails'][0] ?? 'admin@example.org';
        $this->landlord->user_superadmin->user_id = $data['user']['id'];
        $this->landlord->user_superadmin->token = $data['user']['token'];
        $this->landlord->role_superadmin->name = $data['role']['name'] ?? 'Super Admin';
        $this->landlord->role_superadmin->id = $data['role']['id'];
        $this->landlord->tenant_primary->slug = $data['tenant']['slug'];
        $this->landlord->tenant_primary->subdomain = $data['tenant']['subdomain'] ?? $this->landlord->tenant_primary->subdomain;
        $this->landlord->tenant_primary->id = $data['tenant']['id'];
        $this->landlord->tenant_primary->role_admin->id = $data['tenant']['role_admin_id'];

        $this->ensureCrossTenantUsers();

        $this->makeTenantCurrent();

        static::$systemInitialized = true;
    }

    protected function hydrateFromDatabase(): void
    {
        $user = LandlordUser::query()->first();
        $tenant = Tenant::query()->first();
        $role = LandlordRole::query()->first();

        if ($user) {
            $token = $user->createToken(
                'Test Token',
                $this->sanitizeAbilities($user->getPermissions())
            )->plainTextToken;
            $this->landlord->user_superadmin->name = $user->name;
            $this->landlord->user_superadmin->email_1 = $user->emails[0] ?? $user->email ?? '';
            $this->landlord->user_superadmin->user_id = (string) $user->_id;
            $this->landlord->user_superadmin->token = $token;

            $this->ensureCrossTenantUsers();
        }

        if ($role) {
            $this->landlord->role_superadmin->name = $role->name;
            $this->landlord->role_superadmin->id = (string) $role->_id;
        }

        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = $tenant->roleTemplates()->first()?->_id ?? '';
            $tenant->makeCurrent();
        }
    }

    protected function makeTenantCurrent(): void
    {
        $tenant = Tenant::query()->where('slug', $this->landlord->tenant_primary->slug)->first();
        if ($tenant) {
            $tenant->makeCurrent();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function initializationPayload(): array
    {
        $faviconFixturePath = base_path('tests/Assets/landlord.ico');
        $favicon = new UploadedFile(
            $faviconFixturePath,
            'favicon.ico',
            'image/vnd.microsoft.icon',
            null,
            true
        );

        return [
            'landlord' => [
                'name' => 'Belluga HQ',
            ],
            'user' => [
                'name' => 'Admin User',
                'email' => 'admin@belluga.test',
                'password' => 'Belluga!123',
            ],
            'tenant' => [
                'name' => 'Belluga Solutions Test',
                'subdomain' => 'belluga-test',
                'domains' => ['tenant.belluga.test'],
            ],
            'role' => [
                'name' => 'Super Admin',
                'permissions' => ['*'],
            ],
            'branding_data' => [
                'theme_data_settings' => [
                    'brightness_default' => 'light',
                    'primary_seed_color' => '#FFFFFF',
                    'secondary_seed_color' => '#999999',
                ],
                'logo_settings' => [
                    'light_logo_uri' => UploadedFile::fake()->image('light-logo.png', 350, 512),
                    'dark_logo_uri' => UploadedFile::fake()->image('dark-logo.png', 400, 512),
                    'light_icon_uri' => UploadedFile::fake()->image('light-icon.png', 128, 128),
                    'dark_icon_uri' => UploadedFile::fake()->image('dark-icon.png', 128, 128),
                    'favicon_uri' => $favicon,
                ],
                'pwa_icon' => UploadedFile::fake()->image('pwa-icon.png', 1024, 1024),
            ],
        ];
    }

    protected function ensureCrossTenantUsers(): void
    {
        $adminEmail = 'cross-admin@belluga.test';
        $visitorEmail = 'cross-visitor@belluga.test';

        $crossAdmin = LandlordUser::query()
            ->where('emails', 'all', [$adminEmail])
            ->first();

        if (! $crossAdmin) {
            $crossAdmin = LandlordUser::create([
                'name' => 'Cross Tenant Admin',
                'emails' => [$adminEmail],
                'password' => Hash::make('Secret!234'),
                'identity_state' => 'registered',
            ]);
        }

        $adminToken = $crossAdmin->createToken(
            'Test Token',
            $this->sanitizeAbilities($crossAdmin->getPermissions())
        )->plainTextToken;

        $this->landlord->user_cross_tenant_admin->name = $crossAdmin->name;
        $this->landlord->user_cross_tenant_admin->email_1 = $crossAdmin->emails[0] ?? $adminEmail;
        $this->landlord->user_cross_tenant_admin->user_id = (string) $crossAdmin->_id;
        $this->landlord->user_cross_tenant_admin->password = 'Secret!234';
        $this->landlord->user_cross_tenant_admin->token = $adminToken;

        $crossVisitor = LandlordUser::query()
            ->where('emails', 'all', [$visitorEmail])
            ->first();

        if (! $crossVisitor) {
            $crossVisitor = LandlordUser::create([
                'name' => 'Cross Tenant Visitor',
                'emails' => [$visitorEmail],
                'password' => Hash::make('Secret!234'),
                'identity_state' => 'registered',
            ]);
        }

        $visitorToken = $crossVisitor->createToken(
            'Test Token',
            $this->sanitizeAbilities($crossVisitor->getPermissions())
        )->plainTextToken;

        $this->landlord->user_cross_tenant_visitor->name = $crossVisitor->name;
        $this->landlord->user_cross_tenant_visitor->email_1 = $crossVisitor->emails[0] ?? $visitorEmail;
        $this->landlord->user_cross_tenant_visitor->user_id = (string) $crossVisitor->_id;
        $this->landlord->user_cross_tenant_visitor->password = 'Secret!234';
        $this->landlord->user_cross_tenant_visitor->token = $visitorToken;
    }

    /**
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            return \App\Support\Auth\AbilityCatalog::all();
        }

        return $abilities;
    }

}
