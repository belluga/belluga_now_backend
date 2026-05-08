<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Application\LandlordUsers\LandlordUserAccessService;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class RegisterAdministratorUserAction
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService
    ) {}

    /**
     * @param  array<string, mixed>  $userData
     */
    public function execute(array $userData, LandlordRole $role, TenantRoleTemplate $tenantTemplate): LandlordUser
    {
        $primaryEmail = strtolower($userData['email']);

        $user = LandlordUser::query()
            ->where('emails', 'all', [$primaryEmail])
            ->first();
        $secretHash = Hash::make((string) $userData['password']);

        if (! $user) {
            $user = LandlordUser::create([
                'name' => $userData['name'],
                'emails' => [$primaryEmail],
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
            $secretHash = $this->accessService->credential($user, 'password', $primaryEmail)['secret_hash'] ?? null;
            if (! is_string($secretHash) || $secretHash === '') {
                $secretHash = $this->accessService->firstPasswordCredentialHash($user) ?? Hash::make((string) $userData['password']);
            }
        }

        $this->accessService->ensureEmail($user, $primaryEmail);
        $this->accessService->syncPasswordCredentialsForEmails($user, $secretHash);
        $this->accessService->removeLegacyPasswordState($user);

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
