<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MongoDB\BSON\ObjectId;

class LandlordUserCreator
{
    public function __construct(
        private readonly LandlordUserAccessService $accessService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, string $roleId, ?string $operatorId = null): LandlordUser
    {
        $role = LandlordRole::where('_id', new ObjectId($roleId))->firstOrFail();

        return DB::connection('landlord')->transaction(function () use ($payload, $role, $operatorId): LandlordUser {
            $email = strtolower($payload['email']);
            $secretHash = Hash::make((string) $payload['password']);

            $promotionAuditEntry = [
                'from_state' => 'anonymous',
                'to_state' => 'registered',
                'promoted_at' => Carbon::now(),
                'operator_id' => $this->buildOperatorObjectId($operatorId),
            ];

            $user = LandlordUser::create([
                'name' => $payload['name'],
                'emails' => [$email],
                'identity_state' => 'registered',
                'credentials' => [],
                'promotion_audit' => [$promotionAuditEntry],
            ]);

            $this->accessService->ensureEmail($user, $email);
            $this->accessService->syncPasswordCredentialsForEmails($user, $secretHash);
            $this->accessService->removeLegacyPasswordState($user);

            $role->users()->save($user);

            return $user;
        });
    }

    private function buildOperatorObjectId(?string $operatorId): ?ObjectId
    {
        if (! is_string($operatorId) || $operatorId === '') {
            return null;
        }

        try {
            return new ObjectId($operatorId);
        } catch (\Throwable) {
            return null;
        }
    }
}
