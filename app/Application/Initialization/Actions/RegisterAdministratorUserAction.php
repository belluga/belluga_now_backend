<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Support\Carbon;

class RegisterAdministratorUserAction
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService
    ) {
    }

    /**
     * @param array<string, mixed> $userData
     */
    public function execute(array $userData, LandlordRole $role, TenantRoleTemplate $tenantTemplate): LandlordUser
    {
        $primaryEmail = strtolower($userData['email']);

        $user = LandlordUser::query()
            ->where('emails', 'all', [$primaryEmail])
            ->first();

        if (! $user) {
            $user = LandlordUser::create([
                'name' => $userData['name'],
                'emails' => [$primaryEmail],
                'password' => $userData['password'],
                'identity_state' => 'validated',
                'verified_at' => Carbon::now(),
                'promotion_audit' => [
                    [
                        'from_state' => 'registered',
                        'to_state' => 'validated',
                        'promoted_at' => Carbon::now(),
                        'operator_id' => null,
                    ],
                ],
            ]);
        } else {
            $user->name = $userData['name'];
            $user->save();
        }

        $this->accessService->ensureEmail($user, $primaryEmail);
        $this->accessService->syncCredential($user, 'password', $primaryEmail, $user->password);

        $role->users()->save($user);

        $existingTenantRole = $user->tenantRoles()
            ->where('tenant_id', $tenantTemplate->tenant_id)
            ->first();
        if (! $existingTenantRole) {
            $user->tenantRoles()->create([
                ...$tenantTemplate->attributesToArray(),
                'tenant_id' => $tenantTemplate->tenant_id,
            ]);
        }

        return $user;
    }
}
