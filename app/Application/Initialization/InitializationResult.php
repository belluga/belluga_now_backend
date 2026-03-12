<?php

declare(strict_types=1);

namespace App\Application\Initialization;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;

class InitializationResult
{
    public function __construct(
        public readonly Landlord $landlord,
        public readonly Tenant $tenant,
        public readonly LandlordRole $adminRole,
        public readonly TenantRoleTemplate $tenantAdminTemplate,
        public readonly LandlordUser $user,
        public readonly string $token
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toResponsePayload(): array
    {
        return [
            'user' => [
                'token' => $this->token,
                ...$this->user->toArray(),
            ],
            'tenant' => [
                ...$this->tenant->attributesToArray(),
                'role_admin_id' => $this->tenantAdminTemplate->id,
            ],
            'role' => $this->adminRole->toArray(),
            'landlord' => $this->landlord->toArray(),
        ];
    }
}
