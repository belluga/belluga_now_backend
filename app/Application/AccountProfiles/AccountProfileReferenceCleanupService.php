<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Belluga\MapPois\Application\MapPoiProjectionService;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Removes references to terminalized Profiles without routing legacy stored
 * values through tenant-admin validation intended for interactive writes.
 */
final class AccountProfileReferenceCleanupService
{
    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileMutationGate $mutationGate,
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
        private readonly AccountProfileNestedGroupMemberStore $nestedGroupMemberStore,
        private readonly MapPoiProjectionService $mapPois,
    ) {}

    /** @param list<string> $deletedProfileIds */
    public function cleanSurvivingReferences(string $attemptId, array $deletedProfileIds): void
    {
        $attemptId = trim($attemptId);
        $deletedProfileIds = $this->normalizedIds($deletedProfileIds);
        if ($attemptId === '' || $deletedProfileIds === []) {
            return;
        }

        $profileIds = $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $context): array => $this->survivingProfiles($context, $deletedProfileIds)
                ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
                ->all(),
        );

        foreach ($profileIds as $profileId) {
            $profileId = trim($profileId);
            if ($profileId === '') {
                continue;
            }

            $commandId = "current-account-delete:{$attemptId}:reference-cleanup:{$profileId}";
            $fingerprint = $this->cleanupFingerprint($profileId, $deletedProfileIds, $attemptId);
            $eventId = $this->transactionRunner->run(
                fn (AccountProfileTransactionContext $context): ?string => $this->cleanProfileWithinTransaction(
                    $profileId,
                    $deletedProfileIds,
                    $context,
                    $commandId,
                    $fingerprint,
                    $this->nestedGroupMemberStore->metadataGroupsForProfilesWithinContext($context, [$profileId])[$profileId] ?? [],
                ),
                fn (): ?string => $this->reconcileCommittedCleanup($commandId, $fingerprint),
            );

            if ($eventId !== null) {
                $this->outboxDispatcher->dispatchEvent($eventId);
            }
        }
    }

    /**
     * Cleans all currently surviving parents inside a caller-owned lifecycle
     * transaction. The caller must dispatch the returned outbox events only
     * after that transaction commits.
     *
     * @param  list<string>  $deletedProfileIds
     * @return list<string>
     */
    public function cleanSurvivingReferencesWithinTransaction(
        AccountProfileTransactionContext $context,
        string $operationCommandId,
        array $deletedProfileIds,
    ): array {
        $operationCommandId = trim($operationCommandId);
        $deletedProfileIds = $this->normalizedIds($deletedProfileIds);
        if ($operationCommandId === '' || $deletedProfileIds === []) {
            return [];
        }

        $profiles = $this->survivingProfiles($context, $deletedProfileIds);
        $groupsByProfileId = $this->nestedGroupMemberStore->metadataGroupsForProfilesWithinContext(
            $context,
            $profiles->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())->all(),
        );

        $eventIds = [];
        foreach ($profiles as $profile) {
            $profileId = trim((string) $profile->getKey());
            if ($profileId === '') {
                continue;
            }

            $commandId = "{$operationCommandId}:reference-cleanup:{$profileId}";
            $eventId = $this->cleanProfileWithinTransaction(
                $profileId,
                $deletedProfileIds,
                $context,
                $commandId,
                $this->cleanupFingerprint($profileId, $deletedProfileIds, $operationCommandId),
                $groupsByProfileId[$profileId] ?? [],
            );
            if ($eventId !== null) {
                $eventIds[] = $eventId;
            }
        }

        return array_values(array_unique($eventIds));
    }

    /** @param list<string> $profileIds */
    public function purgeProfileGraphWithinTransaction(
        AccountProfileTransactionContext $context,
        array $profileIds,
    ): void {
        $profileIds = $this->normalizedIds($profileIds);
        if ($profileIds === []) {
            return;
        }

        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        $nestedFilter = [
            '$or' => [
                ['parent_type' => AccountProfileNestedGroupMemberStore::PARENT_TYPE, 'parent_id' => ['$in' => $profileIds]],
                ['doc_type' => 'member_row', 'nested_profile.id' => ['$in' => $profileIds]],
            ],
        ];
        if ($tenantId !== '') {
            $nestedFilter['tenant_id'] = $tenantId;
        }
        $context->collection(AccountProfileNestedGroupMemberStore::COLLECTION)->deleteMany(
            $nestedFilter,
            $context->rawOptions(),
        );

        $projectionFilter = [
            '$or' => [
                ['parent_profile_id' => ['$in' => $profileIds]],
                ['member_profile_id' => ['$in' => $profileIds]],
            ],
        ];
        if ($tenantId !== '') {
            $projectionFilter['tenant_id'] = $tenantId;
        }
        $context->collection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->deleteMany(
            $projectionFilter,
            $context->rawOptions(),
        );

        $this->mapPois->deleteByRefsWithinTransaction(
            $context->database(),
            $context->session(),
            'account_profile',
            $profileIds,
        );
    }

    /**
     * @param  list<string>  $deletedProfileIds
     */
    private function cleanProfileWithinTransaction(
        string $profileId,
        array $deletedProfileIds,
        AccountProfileTransactionContext $context,
        string $commandId,
        string $fingerprint,
        array $groups,
    ): ?string {
        $receipt = $this->outboxPublisher->receipt($context, $commandId);
        if ($receipt !== null) {
            $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

            return $this->eventIdFromReceipt($receipt);
        }

        $profile = AccountProfile::withTrashed()->find($profileId);
        if (! $profile instanceof AccountProfile) {
            return null;
        }

        $this->mutationGate->assertProfileMutationAllowed($profile, $context);
        $attributes = $this->cleanupAttributes($profile, $deletedProfileIds, $groups);
        if ($attributes === []) {
            return null;
        }

        $profile->fill($attributes);
        $profile->setAttribute(
            'aggregate_revision',
            max(0, (int) $profile->getAttribute('aggregate_revision')) + 1,
        );
        $profile->save();

        return $this->outboxPublisher->recordUpsert($context, $profile, $commandId, $fingerprint);
    }

    /**
     * @param  list<string>  $deletedProfileIds
     * @return array<string, mixed>
     */
    private function cleanupAttributes(
        AccountProfile $profile,
        array $deletedProfileIds,
        array $groups,
    ): array {
        $attributes = [];
        $sourceProfileId = trim((string) ($profile->contact_source_account_profile_id ?? ''));
        if (in_array($sourceProfileId, $deletedProfileIds, true)) {
            $attributes['contact_mode'] = AccountProfileContactChannelsService::CONTACT_MODE_OWN;
            $attributes['contact_source_account_profile_id'] = null;
            $attributes['contact_bubble_channel_id'] = null;
        }

        $cleanedGroups = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $memberIds = $this->normalizedIds((array) ($group['member_ids'] ?? []));
            $cleanedMemberIds = array_values(array_filter(
                $memberIds,
                fn (mixed $memberId): bool => ! in_array(trim((string) $memberId), $deletedProfileIds, true),
            ));
            $group['member_count'] = count($cleanedMemberIds);
            unset($group['member_ids']);
            $cleanedGroups[] = $group;
        }

        // Group heads and member rows are canonical. Replacing the persisted
        // mirror with their cleaned metadata also drops stale embedded-only
        // references (including the retired account_profile_ids payload).
        if ($this->plainArray($profile->nested_profile_groups ?? []) !== $cleanedGroups) {
            $attributes['nested_profile_groups'] = $cleanedGroups;
        }

        return $attributes;
    }

    /** @param array<string, mixed> $receipt */
    private function eventIdFromReceipt(array $receipt): ?string
    {
        $eventId = trim((string) ($receipt['outbox_event_id'] ?? ''));

        return $eventId === '' ? null : $eventId;
    }

    private function reconcileCommittedCleanup(string $commandId, string $fingerprint): ?string
    {
        $receipt = $this->outboxPublisher->committedReceipt($commandId);
        if ($receipt === null) {
            return null;
        }

        $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

        return $this->eventIdFromReceipt($receipt);
    }

    /** @param list<string> $deletedProfileIds */
    private function survivingProfiles(
        AccountProfileTransactionContext $context,
        array $deletedProfileIds,
    ): \Illuminate\Support\Collection
    {
        $parentIdsFromMemberRows = $this->normalizedIds(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                $context
                    ->database()
                    ->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)
                    ->distinct('parent_id', [
                        'parent_type' => AccountProfileNestedGroupMemberStore::PARENT_TYPE,
                        'doc_type' => 'member_row',
                        'nested_profile.id' => ['$in' => $deletedProfileIds],
                    ], $context->rawOptions()),
            ),
        );

        return AccountProfile::withTrashed()
            ->whereNotIn('_id', $deletedProfileIds)
            ->where(function ($query) use ($deletedProfileIds, $parentIdsFromMemberRows): void {
                $query
                    ->whereIn('contact_source_account_profile_id', $deletedProfileIds)
                    ->orWhereIn('nested_profile_groups.account_profile_ids', $deletedProfileIds);

                if ($parentIdsFromMemberRows !== []) {
                    $query->orWhereIn('_id', $parentIdsFromMemberRows);
                }
            })
            ->orderBy('_id')
            ->get();
    }

    /** @param list<string> $deletedProfileIds */
    private function cleanupFingerprint(string $profileId, array $deletedProfileIds, string $operationCommandId): string
    {
        return $this->outboxPublisher->fingerprintForUpdate(
            $profileId,
            ['reference_cleanup_target_ids' => $deletedProfileIds],
            ['reference_cleanup_command_id' => $operationCommandId],
        );
    }

    /** @return array<int, mixed> */
    private function plainArray(mixed $value): array
    {
        if ($value instanceof BSONArray || $value instanceof BSONDocument) {
            $value = $value->getArrayCopy();
        }
        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $key => $entry) {
            if ($entry instanceof BSONArray || $entry instanceof BSONDocument) {
                $value[$key] = $this->plainArray($entry);
            }
        }

        return $value;
    }

    /** @param array<int, mixed> $ids
     * @return list<string>
     */
    private function normalizedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $ids),
            static fn (string $id): bool => $id !== '',
        )));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
