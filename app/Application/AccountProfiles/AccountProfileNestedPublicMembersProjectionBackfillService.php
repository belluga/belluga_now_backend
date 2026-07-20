<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\Regex;
use RuntimeException;

final class AccountProfileNestedPublicMembersProjectionBackfillService
{
    private const CONSUMER_ID = 'nested_public_members';

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @return array{processed_profiles:int,projected_rows:int,reset:bool}
     */
    public function rebuildCurrentTenant(bool $reset = true): array
    {
        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Nested public members projection rebuild requires a current tenant.');
        }

        if ($reset) {
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)
                ->deleteMany(['tenant_id' => $tenantId]);
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_projection_checkpoints')
                ->deleteMany(['_id' => new Regex('^'.self::CONSUMER_ID.':', '')]);
        }

        $this->container->forgetScopedInstances();
        /** @var AccountProfileNestedPublicMembersProjectionService $projection */
        $projection = $this->container->make(AccountProfileNestedPublicMembersProjectionService::class);

        $processed = 0;
        foreach (AccountProfile::query()->orderBy('_id')->cursor() as $profile) {
            if (! $profile instanceof AccountProfile) {
                continue;
            }

            $projection->rebuildForProfile($profile);
            $processed++;
        }

        return [
            'processed_profiles' => $processed,
            'projected_rows' => DB::connection('tenant')
                ->getDatabase()
                ->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)
                ->countDocuments(['tenant_id' => $tenantId]),
            'reset' => $reset,
        ];
    }
}
