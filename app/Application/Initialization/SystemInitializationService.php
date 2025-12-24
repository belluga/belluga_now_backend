<?php

declare(strict_types=1);

namespace App\Application\Initialization;

use App\Application\Initialization\Actions\CreateAdministratorRoleAction;
use App\Application\Initialization\Actions\CreateLandlordAction;
use App\Application\Initialization\Actions\CreateTenantAction;
use App\Application\Initialization\Actions\CreateTenantAdminTemplateAction;
use App\Application\Initialization\Actions\RegisterAdministratorUserAction;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Support\Auth\AbilityCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemInitializationService
{
    public function __construct(
        private readonly CreateLandlordAction $createLandlord,
        private readonly CreateTenantAction $createTenant,
        private readonly CreateAdministratorRoleAction $createAdminRole,
        private readonly CreateTenantAdminTemplateAction $createTenantTemplate,
        private readonly RegisterAdministratorUserAction $registerAdminUser,
    ) {
    }

    public function isInitialized(): bool
    {
        return LandlordUser::query()->exists()
            || Tenant::query()->exists()
            || Landlord::query()->exists();
    }

    public function initialize(InitializationPayload $payload): InitializationResult
    {
        return DB::connection('landlord')->transaction(function () use ($payload): InitializationResult {
            $landlord = $this->createLandlord->execute(
                $payload->landlord,
                $payload->themeDataSettings,
                $payload->logoSettings,
                $payload->pwaIcon,
            );

            $tenant = $this->createTenant->execute(
                $payload->tenant,
                $payload->tenantDomains,
            );

            $adminRole = $this->createAdminRole->execute($payload->role);
            $this->warnOnWildcardRolePermissions($adminRole->permissions ?? []);

            $tenantTemplate = $this->createTenantTemplate->execute($tenant);

            $user = $this->registerAdminUser->execute(
                $payload->user,
                $adminRole,
                $tenantTemplate
            );

            $token = $user->createToken(
                'Initialization Token',
                $this->sanitizeAbilities($user->getPermissions())
            )->plainTextToken;

            return new InitializationResult(
                $landlord,
                $tenant,
                $adminRole,
                $tenantTemplate,
                $user,
                $token
            );
        });
    }

    /**
     * @param array<int, string> $abilities
     * @return array<int, string>
     */
    private function sanitizeAbilities(array $abilities): array
    {
        if (in_array('*', $abilities, true)) {
            return AbilityCatalog::all();
        }

        return $abilities;
    }

    /**
     * @param array<int, string> $permissions
     */
    private function warnOnWildcardRolePermissions(array $permissions): void
    {
        if (in_array('*', $permissions, true)) {
            Log::warning('Wildcard permission detected in initialization role payload.', [
                'permissions' => $permissions,
            ]);
        }
    }
}
