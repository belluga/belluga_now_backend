<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Accounts\AccountPublicationStateService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\Settings\Contracts\SettingsStoreContract;

final class HomeFavoritesPinnedProfileService
{
    public const string NAMESPACE = 'home_favorites_pinned_profile';

    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly AccountPublicationStateService $publicationStateService,
    ) {}

    public function storedProfileId(): ?string
    {
        $value = $this->settingsStore->getNamespaceValue('tenant', self::NAMESPACE);
        $profileId = trim((string) ($value['account_profile_id'] ?? ''));

        return $profileId === '' ? null : $profileId;
    }

    public function findEligibleProfile(string $profileId): ?AccountProfile
    {
        $normalizedId = trim($profileId);
        if ($normalizedId === '') {
            return null;
        }

        $profile = AccountProfile::withTrashed()->where('_id', $normalizedId)->first();
        if (! $profile instanceof AccountProfile || ! $this->profilePolicy()->canOpenPublicDetail($profile)) {
            return null;
        }

        $account = Account::withTrashed()->where('_id', trim((string) $profile->account_id))->first();
        if (! $account instanceof Account || $account->trashed()) {
            return null;
        }

        if ($this->ownershipStateService->deriveOwnershipState($account) !== AccountOwnershipStateService::TENANT_OWNED) {
            return null;
        }

        return $this->publicationStateService->isPublished($account->publication)
            ? $profile
            : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function candidateAccountGateStages(): array
    {
        $tenantOrganizationId = $this->ownershipStateService->tenantOrganizationId();
        $legacyTenantOwned = [
            '$and' => [
                [
                    '$or' => [
                        ['ownership_state' => ['$exists' => false]],
                        ['ownership_state' => null],
                        ['ownership_state' => ''],
                    ],
                ],
                ['organization_id' => $tenantOrganizationId ?? '__missing_tenant_organization__'],
            ],
        ];

        return [
            [
                '$lookup' => [
                    'from' => 'accounts',
                    'let' => ['parent_account_id' => '$account_id'],
                    'pipeline' => [
                        [
                            '$match' => [
                                '$expr' => [
                                    '$eq' => [
                                        '$_id',
                                        [
                                            '$convert' => [
                                                'input' => '$$parent_account_id',
                                                'to' => 'objectId',
                                                'onError' => null,
                                                'onNull' => null,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        ['$match' => ['publication.status' => AccountPublicationStateService::PUBLISHED]],
                        [
                            '$match' => [
                                '$or' => [
                                    ['ownership_state' => AccountOwnershipStateService::TENANT_OWNED],
                                    $legacyTenantOwned,
                                ],
                            ],
                        ],
                        [
                            '$lookup' => [
                                'from' => 'account_users',
                                'let' => ['candidate_account_id' => ['$toString' => '$_id']],
                                'pipeline' => [
                                    [
                                        '$match' => [
                                            '$expr' => [
                                                '$in' => [
                                                    '$$candidate_account_id',
                                                    [
                                                        '$map' => [
                                                            'input' => ['$ifNull' => ['$account_roles', []]],
                                                            'as' => 'role',
                                                            'in' => '$$role.account_id',
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    ['$limit' => 1],
                                    ['$project' => ['_id' => 1]],
                                ],
                                'as' => 'operators',
                            ],
                        ],
                        [
                            '$match' => [
                                '$or' => [
                                    ['ownership_state' => AccountOwnershipStateService::TENANT_OWNED],
                                    ['operators.0' => ['$exists' => false]],
                                ],
                            ],
                        ],
                        ['$project' => ['_id' => 1]],
                    ],
                    'as' => 'eligible_parent_accounts',
                ],
            ],
            [
                '$match' => [
                    'eligible_parent_accounts.0' => ['$exists' => true],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function candidateProfileMatchExpression(string $normalizedSearch): array
    {
        return AccountProfileSearchV1::mongoScopedOrPredicate(
            $this->profilePolicy()->publicDetailMatchExpression(requireSlug: true),
            'name_search_key',
            'search_terms',
            $normalizedSearch,
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public function adminProjection(array $value): array
    {
        $profileId = trim((string) ($value['account_profile_id'] ?? ''));
        if ($profileId === '') {
            return [
                'setting_type' => self::NAMESPACE,
                'value' => ['account_profile_id' => null],
                'availability' => 'unset',
                'selected_profile' => null,
            ];
        }

        $profile = $this->findEligibleProfile($profileId);

        return [
            'setting_type' => self::NAMESPACE,
            'value' => ['account_profile_id' => $profileId],
            'availability' => $profile instanceof AccountProfile ? 'available' : 'unavailable',
            'selected_profile' => $profile instanceof AccountProfile ? [
                'id' => (string) $profile->getKey(),
                'display_name' => (string) $profile->display_name,
            ] : null,
        ];
    }

    private function profilePolicy(): AccountProfilePublicCatalogEligibilityPolicy
    {
        return $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
    }
}
