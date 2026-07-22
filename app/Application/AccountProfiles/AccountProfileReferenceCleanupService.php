<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /** @param list<string> $deletedProfileIds */
    public function cleanSurvivingReferences(string $attemptId, array $deletedProfileIds): void
    {
        $attemptId = trim($attemptId);
        $deletedProfileIds = $this->normalizedIds($deletedProfileIds);
        if ($attemptId === '' || $deletedProfileIds === []) {
            return;
        }

        $profiles = $this->survivingProfiles($deletedProfileIds);

        foreach ($profiles as $profile) {
            $profileId = trim((string) $profile->getKey());
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

        $eventIds = [];
        foreach ($this->survivingProfiles($deletedProfileIds) as $profile) {
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
            );
            if ($eventId !== null) {
                $eventIds[] = $eventId;
            }
        }

        return array_values(array_unique($eventIds));
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
        $attributes = $this->cleanupAttributes($profile, $deletedProfileIds, $context);
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
        AccountProfileTransactionContext $context,
    ): array
    {
        $attributes = [];
        $sourceProfileId = trim((string) ($profile->contact_source_account_profile_id ?? ''));
        if (in_array($sourceProfileId, $deletedProfileIds, true)) {
            $attributes['contact_mode'] = AccountProfileContactChannelsService::CONTACT_MODE_OWN;
            $attributes['contact_source_account_profile_id'] = null;
            $attributes['contact_bubble_channel_id'] = null;
        }

        $groups = $this->nestedGroupMemberStore->metadataGroupsWithinContext($context, $profile);
        $cleanedGroups = [];
        $groupsChanged = false;
        foreach ($groups as $group) {
            if (! is_array($group)) {
                $cleanedGroups[] = $group;

                continue;
            }

            $groupId = trim((string) ($group['id'] ?? ''));
            $memberIds = $groupId === ''
                ? []
                : $this->nestedGroupMemberStore->groupMemberIdsWithinContext($context, $profile, $groupId);
            $cleanedMemberIds = array_values(array_filter(
                $memberIds,
                fn (mixed $memberId): bool => ! in_array(trim((string) $memberId), $deletedProfileIds, true),
            ));
            if ($cleanedMemberIds !== $memberIds) {
                $groupsChanged = true;
                if ($groupId !== '') {
                    $this->nestedGroupMemberStore->replaceGroupMembersWithinContext(
                        $context,
                        $profile,
                        $groupId,
                        $cleanedMemberIds,
                    );
                }
            }
            $group['member_count'] = count($cleanedMemberIds);
            $cleanedGroups[] = $group;
        }

        if ($groupsChanged) {
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
    private function survivingProfiles(array $deletedProfileIds): \Illuminate\Support\Collection
    {
        $parentIdsFromMemberRows = $this->normalizedIds(
            array_map(
                static fn (mixed $id): string => trim((string) $id),
                DB::connection('tenant')
                    ->getDatabase()
                    ->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)
                    ->distinct('parent_profile_id', [
                        'doc_type' => 'member_row',
                        'member_profile_id' => ['$in' => $deletedProfileIds],
                    ]),
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
