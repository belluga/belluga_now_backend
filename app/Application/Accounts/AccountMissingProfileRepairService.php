<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Models\Tenants\InviteablePeopleProjection;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Illuminate\Support\Collection;

class AccountMissingProfileRepairService
{
    public function __construct(
        private readonly AccountManagementService $accountManagementService,
        private readonly AccountProfileLifecycleService $profileLifecycle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $execute = false, int $chunkSize = 100): array
    {
        $tenant = Tenant::current();
        $tenantSlug = (string) ($tenant?->slug ?? 'unknown');
        $chunkSize = max(1, min($chunkSize, 500));
        $rows = [];
        $totals = [
            'scanned' => 0,
            'invalid' => 0,
            'would_restore' => 0,
            'restored' => 0,
            'would_delete_test_seed' => 0,
            'deleted_test_seed' => 0,
            'skipped' => 0,
            'residual' => 0,
        ];

        Account::query()
            ->orderBy('_id')
            ->chunk($chunkSize, function ($accounts) use ($execute, $tenantSlug, &$rows, &$totals): void {
                foreach ($accounts as $account) {
                    if (! $account instanceof Account) {
                        continue;
                    }

                    $totals['scanned']++;
                    if ($this->activeProfileCount((string) $account->_id) > 0) {
                        continue;
                    }

                    $totals['invalid']++;
                    $row = $this->classifyAccount($account, $tenantSlug);
                    $row['mode'] = $execute ? 'execute' : 'dry-run';

                    if ($execute) {
                        $row = $this->executeRow($account, $row);
                    }

                    $this->applyTotals($totals, $row);
                    $rows[] = $row;
                }
            });

        return [
            'tenant_slug' => $tenantSlug,
            'mode' => $execute ? 'execute' : 'dry-run',
            'chunk_size' => $chunkSize,
            'totals' => $totals,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function purgeTrashedTestSeedAggregates(bool $execute = false, int $chunkSize = 100): array
    {
        $tenant = Tenant::current();
        $tenantSlug = (string) ($tenant?->slug ?? 'unknown');
        $chunkSize = max(1, min($chunkSize, 500));
        $rows = [];
        $totals = [
            'scanned' => 0,
            'would_purge_test_seed' => 0,
            'purged_test_seed' => 0,
            'skipped' => 0,
            'residual' => 0,
        ];

        Account::onlyTrashed()
            ->orderBy('_id')
            ->chunk($chunkSize, function ($accounts) use ($tenantSlug, &$rows, &$totals): void {
                foreach ($accounts as $account) {
                    if (! $account instanceof Account) {
                        continue;
                    }

                    $totals['scanned']++;
                    $row = $this->classifyTrashedTestSeedAccount($account, $tenantSlug);
                    $row['mode'] = 'dry-run';
                    $this->applyPurgeTotals($totals, $row);
                    $rows[] = $row;
                }
            });

        if ($execute) {
            $totals = [
                'scanned' => $totals['scanned'],
                'would_purge_test_seed' => 0,
                'purged_test_seed' => 0,
                'skipped' => 0,
                'residual' => 0,
            ];

            foreach ($rows as $index => $row) {
                $row['mode'] = 'execute';
                if (($row['action'] ?? null) === 'purge_test_seed_account') {
                    $account = Account::withTrashed()->find((string) ($row['account_id'] ?? ''));
                    if ($account instanceof Account) {
                        $this->accountManagementService->deleteRepairApprovedTestSeedAggregate($account);
                        $row['executed'] = true;
                        $row['residual_reason'] = null;
                    }
                }

                $rows[$index] = $row;
                $this->applyPurgeTotals($totals, $row);
            }
        }

        return [
            'tenant_slug' => $tenantSlug,
            'mode' => $execute ? 'execute' : 'dry-run',
            'chunk_size' => $chunkSize,
            'totals' => $totals,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyAccount(Account $account, string $tenantSlug): array
    {
        $accountId = (string) $account->_id;
        $restorableProfiles = AccountProfile::onlyTrashed()
            ->where('account_id', $accountId)
            ->orderBy('deleted_at', 'desc')
            ->get();
        $profileIds = $restorableProfiles
            ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
            ->values()
            ->all();

        $base = [
            'tenant_slug' => $tenantSlug,
            'account_id' => $accountId,
            'account_slug' => (string) ($account->slug ?? ''),
            'account_name' => (string) ($account->name ?? ''),
            'ownership_state' => (string) ($account->ownership_state ?? ''),
            'profile_ids' => $profileIds,
            'profile_id' => $profileIds[0] ?? null,
            'action' => 'skip',
            'policy_branch' => 'unclassified',
            'residual_reason' => null,
            'executed' => false,
        ];

        $linkedData = $this->linkedDataCheck($account, $restorableProfiles);
        if ($this->isKnownTestSeedAccount($account)) {
            if (! $this->linkedDataAllowsTestSeedDeletion($linkedData)) {
                return [
                    ...$base,
                    'policy_branch' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                    'residual_reason' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                    'linked_data' => $linkedData['checks'] ?? [],
                ];
            }

            return [
                ...$base,
                'action' => 'delete_test_seed_account',
                'policy_branch' => 'safe_test_seed_aggregate_deletion',
                'linked_data' => $linkedData['checks'] ?? [],
            ];
        }

        if (! (bool) ($linkedData['passes'] ?? false)) {
            return [
                ...$base,
                'policy_branch' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                'residual_reason' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                'linked_data' => $linkedData['checks'] ?? [],
            ];
        }

        if ($restorableProfiles->count() > 1) {
            return [
                ...$base,
                'policy_branch' => 'multiple_soft_deleted_profiles',
                'residual_reason' => 'multiple_soft_deleted_profiles',
            ];
        }

        if ($restorableProfiles->count() !== 1) {
            return [
                ...$base,
                'policy_branch' => 'no_restorable_profile',
                'residual_reason' => 'no_restorable_profile',
            ];
        }

        /** @var AccountProfile $profile */
        $profile = $restorableProfiles->first();
        if (! $this->profileTypeExists((string) $profile->profile_type)) {
            return [
                ...$base,
                'policy_branch' => 'missing_profile_type',
                'residual_reason' => 'missing_profile_type',
            ];
        }

        return [
            ...$base,
            'action' => 'restore_profile',
            'policy_branch' => 'safe_restore',
            'profile_id' => (string) $profile->_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyTrashedTestSeedAccount(Account $account, string $tenantSlug): array
    {
        $accountId = (string) $account->_id;
        $profiles = AccountProfile::withTrashed()
            ->where('account_id', $accountId)
            ->get();
        $profileIds = $profiles
            ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
            ->values()
            ->all();

        $base = [
            'tenant_slug' => $tenantSlug,
            'account_id' => $accountId,
            'account_slug' => (string) ($account->slug ?? ''),
            'account_name' => (string) ($account->name ?? ''),
            'ownership_state' => (string) ($account->ownership_state ?? ''),
            'profile_ids' => $profileIds,
            'action' => 'skip',
            'policy_branch' => 'unclassified',
            'residual_reason' => null,
            'executed' => false,
        ];

        if (! $this->isKnownTrashedTestSeedAccount($account)) {
            return [
                ...$base,
                'policy_branch' => 'not_known_test_seed',
                'residual_reason' => 'not_known_test_seed',
            ];
        }

        $linkedData = $this->linkedDataCheck($account, $profiles);
        if (! $this->linkedDataAllowsTestSeedDeletion($linkedData)) {
            return [
                ...$base,
                'policy_branch' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                'residual_reason' => (string) ($linkedData['reason'] ?? 'linked_data_not_safe'),
                'linked_data' => $linkedData['checks'] ?? [],
            ];
        }

        return [
            ...$base,
            'action' => 'purge_test_seed_account',
            'policy_branch' => 'safe_test_seed_aggregate_purge',
            'linked_data' => $linkedData['checks'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function executeRow(Account $account, array $row): array
    {
        if ($row['action'] === 'restore_profile' && is_string($row['profile_id'])) {
            $profile = AccountProfile::onlyTrashed()->find($row['profile_id']);
            if ($profile instanceof AccountProfile) {
                $this->profileLifecycle->restore(
                    $profile,
                    sprintf(
                        'account-missing-profile-repair:%s:%s:restore',
                        (string) $account->getKey(),
                        (string) $profile->getKey(),
                    ),
                );
                $row['executed'] = true;
                $row['residual_reason'] = null;
            }

            return $row;
        }

        if ($row['action'] === 'delete_test_seed_account') {
            $this->accountManagementService->deleteRepairApprovedTestSeedAggregate($account);
            $row['executed'] = true;
            $row['residual_reason'] = null;

            return $row;
        }

        return $row;
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $row
     */
    private function applyTotals(array &$totals, array $row): void
    {
        $action = (string) ($row['action'] ?? 'skip');
        $executed = (bool) ($row['executed'] ?? false);

        if ($action === 'restore_profile') {
            $totals[$executed ? 'restored' : 'would_restore']++;

            return;
        }

        if ($action === 'delete_test_seed_account') {
            $totals[$executed ? 'deleted_test_seed' : 'would_delete_test_seed']++;

            return;
        }

        $totals['skipped']++;
        $totals['residual']++;
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $row
     */
    private function applyPurgeTotals(array &$totals, array $row): void
    {
        $action = (string) ($row['action'] ?? 'skip');
        $executed = (bool) ($row['executed'] ?? false);

        if ($action === 'purge_test_seed_account') {
            $totals[$executed ? 'purged_test_seed' : 'would_purge_test_seed']++;

            return;
        }

        $totals['skipped']++;
        $totals['residual']++;
    }

    private function activeProfileCount(string $accountId): int
    {
        return AccountProfile::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->count();
    }

    private function isKnownTestSeedAccount(Account $account): bool
    {
        $slug = (string) ($account->slug ?? '');

        if ($slug === 'runtime-invite-account' || str_starts_with($slug, 'runtime-invite-account-')) {
            return true;
        }

        if ((string) ($account->ownership_state ?? '') !== AccountOwnershipStateService::TENANT_OWNED) {
            return false;
        }

        return $this->isKnownHarnessTestSeedSlug($slug);
    }

    private function isKnownTrashedTestSeedAccount(Account $account): bool
    {
        $slug = (string) ($account->slug ?? '');

        return $slug === 'runtime-invite-account'
            || str_starts_with($slug, 'runtime-invite-account-')
            || $this->isKnownHarnessTestSeedSlug($slug);
    }

    private function isKnownHarnessTestSeedSlug(string $slug): bool
    {
        return str_starts_with($slug, 'playwright-')
            || str_starts_with($slug, 'pw-sr-d-')
            || str_starts_with($slug, 'pw-nested-');
    }

    private function profileTypeExists(string $profileType): bool
    {
        return trim($profileType) !== ''
            && TenantProfileType::query()->where('type', $profileType)->exists();
    }

    /**
     * @param  Collection<int, AccountProfile>  $profiles
     * @return array{passes:bool, reason:string|null, checks:array<string, int>}
     */
    private function linkedDataCheck(Account $account, Collection $profiles): array
    {
        $accountId = (string) $account->_id;
        $profileIds = $profiles
            ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
            ->filter(static fn (string $id): bool => trim($id) !== '')
            ->values()
            ->all();

        $checks = [
            'account_users' => AccountUser::query()->where('account_id', $accountId)->count(),
            'events' => $this->eventsReferenceCount($accountId, $profileIds, includeTrashed: true),
            'active_events' => $this->eventsReferenceCount($accountId, $profileIds, includeTrashed: false),
            'event_occurrences' => $this->eventOccurrencesReferenceCount($profileIds, includeTrashed: true),
            'active_event_occurrences' => $this->eventOccurrencesReferenceCount($profileIds, includeTrashed: false),
            'invite_edges' => $this->inviteEdgesReferenceCount($profileIds),
            'invite_share_codes' => $this->inviteShareCodesReferenceCount($profileIds),
            'inviteable_people_projection' => $this->inviteablePeopleReferenceCount($profileIds),
        ];

        $hasLinkedData = collect($checks)->contains(static fn (int $count): bool => $count > 0);

        return [
            'passes' => ! $hasLinkedData,
            'reason' => $hasLinkedData ? 'linked_data_present' : null,
            'checks' => $checks,
        ];
    }

    /**
     * @param  array{passes?:bool, reason?:string|null, checks?:array<string, int>}  $linkedData
     */
    private function linkedDataAllowsTestSeedDeletion(array $linkedData): bool
    {
        $checks = $linkedData['checks'] ?? [];
        $hardBlockers = [
            'account_users',
            'active_events',
            'active_event_occurrences',
            'invite_edges',
            'invite_share_codes',
            'inviteable_people_projection',
        ];

        foreach ($hardBlockers as $key) {
            if (! array_key_exists($key, $checks)) {
                return false;
            }

            if ((int) $checks[$key] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function eventsReferenceCount(string $accountId, array $profileIds, bool $includeTrashed): int
    {
        $or = [
            ['account_context_ids' => $accountId],
        ];

        if ($profileIds !== []) {
            $or[] = ['place_ref.id' => ['$in' => $profileIds]];
            $or[] = ['place_ref.ref_id' => ['$in' => $profileIds]];
            $or[] = ['event_parties.id' => ['$in' => $profileIds]];
            $or[] = ['event_parties.account_profile_id' => ['$in' => $profileIds]];
        }

        $query = $includeTrashed ? Event::withTrashed() : Event::query();

        return $query->whereRaw(['$or' => $or])->count();
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function eventOccurrencesReferenceCount(array $profileIds, bool $includeTrashed): int
    {
        if ($profileIds === []) {
            return 0;
        }

        $query = $includeTrashed ? EventOccurrence::withTrashed() : EventOccurrence::query();

        return $query->whereRaw([
            '$or' => [
                ['own_linked_account_profiles.id' => ['$in' => $profileIds]],
                ['linked_account_profiles.id' => ['$in' => $profileIds]],
                ['programming_items.account_profile_ids' => ['$in' => $profileIds]],
                ['programming_items.linked_account_profiles.id' => ['$in' => $profileIds]],
                ['place_ref.id' => ['$in' => $profileIds]],
                ['place_ref.ref_id' => ['$in' => $profileIds]],
            ],
        ])->count();
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function inviteEdgesReferenceCount(array $profileIds): int
    {
        if ($profileIds === []) {
            return 0;
        }

        return InviteEdge::query()
            ->whereRaw([
                '$or' => [
                    ['account_profile_id' => ['$in' => $profileIds]],
                    ['receiver_account_profile_id' => ['$in' => $profileIds]],
                ],
            ])
            ->count();
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function inviteShareCodesReferenceCount(array $profileIds): int
    {
        if ($profileIds === []) {
            return 0;
        }

        return InviteShareCode::query()
            ->whereIn('account_profile_id', $profileIds)
            ->count();
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function inviteablePeopleReferenceCount(array $profileIds): int
    {
        if ($profileIds === []) {
            return 0;
        }

        return InviteablePeopleProjection::query()
            ->whereIn('receiver_account_profile_id', $profileIds)
            ->count();
    }
}
