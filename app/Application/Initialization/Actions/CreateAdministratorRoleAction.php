<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Models\Landlord\LandlordRole;

class CreateAdministratorRoleAction
{
    /**
     * @param array<string, mixed> $roleData
     */
    public function execute(array $roleData): LandlordRole
    {
        return LandlordRole::create($roleData);
    }
}
