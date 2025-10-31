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
use Illuminate\Support\Facades\DB;

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

            $tenantTemplate = $this->createTenantTemplate->execute($tenant);

            $user = $this->registerAdminUser->execute(
                $payload->user,
                $adminRole,
                $tenantTemplate
            );

            $token = $user->createToken('Initialization Token')->plainTextToken;

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
}
