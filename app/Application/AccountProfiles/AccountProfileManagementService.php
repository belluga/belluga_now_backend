<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Application\Taxonomies\TaxonomyValidationService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Operation\FindOneAndUpdate;

class AccountProfileManagementService
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfileNestedGroupMemberStore $nestedGroupMemberStore,
        private readonly AccountProfileNestedPublicMembersProjectionService $nestedPublicMembersProjectionService,
        private readonly AccountProfileContactChannelsService $contactChannelsService,
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
        private readonly AccountProfileLifecycleService $lifecycleService,
        private readonly AccountProfileRelationAdmissionService $relationAdmissionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        array $payload,
        ?string $commandId = null,
        ?Closure $mutateWithinTransaction = null,
        array $fingerprintSupplement = [],
        ?Closure $compensateKnownRollback = null,
    ): AccountProfile {
        $commandId = $this->normalizeCommandId($commandId);
        $fingerprint = $this->outboxPublisher->fingerprintForCreate($payload, $fingerprintSupplement);

        try {
            /** @var array{profile:AccountProfile,outbox_event_id:?string} $result */
            $result = $this->transactionRunner->run(
                fn (AccountProfileTransactionContext $context): array => $this->createWithinTransactionContext(
                    $payload,
                    $context,
                    $commandId,
                    $fingerprint,
                    $mutateWithinTransaction,
                ),
                fn (): ?array => $this->resultForCommittedCommand($commandId, $fingerprint),
            );
        } catch (AccountProfileCommandIndeterminateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($compensateKnownRollback !== null) {
                try {
                    $compensateKnownRollback();
                } catch (\Throwable $compensationException) {
                    report($compensationException);
                }
            }

            throw $exception;
        }

        if ($result['outbox_event_id'] !== null) {
            $this->outboxDispatcher->dispatchEvent($result['outbox_event_id']);
        }

        return $result['profile'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{profile:AccountProfile,outbox_event_id:?string}
     */
    public function createWithinTransactionContext(
        array $payload,
        AccountProfileTransactionContext $context,
        string $commandId,
        string $fingerprint,
        ?Closure $mutateWithinTransaction = null,
    ): array {
        $existing = $this->resultForCommand($context, $commandId, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $relationNestedGroups = $this->prepareNestedProfileGroupsForWrite(
            (string) ($payload['profile_type'] ?? ''),
            $payload,
        );
        $profile = $this->createWithinCurrentTransaction(
            [...$payload, 'aggregate_revision' => 1],
            $context,
        );
        $relationAttributes = [
            'nested_profile_groups' => $relationNestedGroups ?? [],
            'contact_source_account_profile_id' => $profile->contact_source_account_profile_id,
            'contact_bubble_channel_id' => $profile->contact_bubble_channel_id,
        ];
        $admittedTargets = $this->relationAdmissionService->admit($context, null, $relationAttributes);
        $contactSourceId = trim((string) ($relationAttributes['contact_source_account_profile_id'] ?? ''));
        if ($contactSourceId !== '' && isset($admittedTargets[$contactSourceId])) {
            $this->contactChannelsService->assertMirroredAdmissionStillValid(
                $admittedTargets[$contactSourceId],
                $relationAttributes,
            );
        }
        $this->nestedGroupMemberStore->replaceAllGroupsWithinContext(
            $context,
            $profile,
            $relationNestedGroups ?? [],
            $admittedTargets,
        );
        $this->nestedPublicMembersProjectionService->rebuildForProfileWithinContext($context, $profile);
        if ($mutateWithinTransaction !== null) {
            $mutateWithinTransaction($profile, $context);
            $profile = $profile->fresh();
        }
        $outboxEventId = $this->recordCreatedProfile($context, $profile, $commandId, $fingerprint);

        return [
            'profile' => $profile,
            'outbox_event_id' => $outboxEventId,
        ];
    }

    public function recordCreatedProfile(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $commandId,
        string $fingerprint,
    ): string {
        return $this->outboxPublisher->recordUpsert(
            $context,
            $profile,
            $commandId,
            $fingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createWithinCurrentTransaction(
        array $payload,
        AccountProfileTransactionContext $context,
    ): AccountProfile {
        $payload = AccountProfileRichTextSanitizer::sanitizePayload($payload);

        $this->lifecycleService->assertProfileCreationAllowed($payload, $context);

        $profileType = (string) $payload['profile_type'];

        if (! $this->registryService->typeDefinition($profileType)) {
            throw ValidationException::withMessages([
                'profile_type' => ['Profile type is not supported for this tenant.'],
            ]);
        }

        $accountId = (string) $payload['account_id'];
        if (! Account::query()->where('_id', $accountId)->exists()) {
            throw ValidationException::withMessages([
                'account_id' => ['Account not found.'],
            ]);
        }

        if ($this->registryService->isPoiEnabled($profileType)) {
            $location = $payload['location'] ?? null;
            if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                throw ValidationException::withMessages([
                    'location' => ['Location is required for POI-enabled profiles.'],
                ]);
            }
        }

        $taxonomyTerms = $payload['taxonomy_terms'] ?? [];
        if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
            $this->taxonomyValidationService->assertTermsAllowedForAccountProfile(
                $profileType,
                $taxonomyTerms
            );
            $payload['taxonomy_terms'] = $this->taxonomyTermSummaryResolver->resolve($taxonomyTerms);
            $payload['taxonomy_terms_flat'] = $this->flattenTaxonomyTerms($payload['taxonomy_terms']);
        } elseif (array_key_exists('taxonomy_terms', $payload)) {
            $payload['taxonomy_terms'] = [];
            $payload['taxonomy_terms_flat'] = [];
        }

        $payload = [
            ...$payload,
            ...$this->contactChannelsService->normalizeForWrite($profileType, $payload),
        ];

        try {
            if (! array_key_exists('is_active', $payload)) {
                $payload['is_active'] = true;
            }
            $payload['account_id'] = (string) $payload['account_id'];
            $payload['location'] = $this->formatLocation($payload['location'] ?? null);
            $payload['name_search_key'] = AccountProfileNameSearchKey::fromDisplayName(
                (string) ($payload['display_name'] ?? '')
            );

            $profile = AccountProfile::create($payload)->fresh();
        } catch (BulkWriteException|CommandException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                throw ValidationException::withMessages([
                    'account_profile' => ['Account profile already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account_profile' => ['Something went wrong when trying to create the account profile.'],
            ]);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        AccountProfile $profile,
        array $attributes,
        ?string $commandId = null,
        ?Closure $mutateWithinTransaction = null,
        array $fingerprintSupplement = [],
        bool $dispatchOutboxImmediately = true,
        ?Closure $compensateKnownRollback = null,
        bool $useAggregateRevisionCas = true,
    ): AccountProfile {
        $attributes = AccountProfileRichTextSanitizer::sanitizePayload($attributes);

        $profileType = $profile->profile_type;
        if (array_key_exists('profile_type', $attributes)) {
            $profileType = (string) $attributes['profile_type'];
        }

        if ($profileType && ! $this->registryService->typeDefinition($profileType)) {
            throw ValidationException::withMessages([
                'profile_type' => ['Profile type is not supported for this tenant.'],
            ]);
        }

        if ($profileType && $this->registryService->isPoiEnabled($profileType)) {
            if (array_key_exists('location', $attributes)) {
                $location = $attributes['location'] ?? null;
                if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                    throw ValidationException::withMessages([
                        'location' => ['Location is required for POI-enabled profiles.'],
                    ]);
                }
            }
        }

        if (array_key_exists('taxonomy_terms', $attributes)) {
            $taxonomyTerms = $attributes['taxonomy_terms'] ?? [];
            if (is_array($taxonomyTerms) && $taxonomyTerms !== []) {
                $this->taxonomyValidationService->assertTermsAllowedForAccountProfile(
                    $profileType,
                    $taxonomyTerms
                );
                $attributes['taxonomy_terms'] = $this->taxonomyTermSummaryResolver->resolve($taxonomyTerms);
                $attributes['taxonomy_terms_flat'] = $this->flattenTaxonomyTerms($attributes['taxonomy_terms']);
            } else {
                $attributes['taxonomy_terms'] = [];
                $attributes['taxonomy_terms_flat'] = [];
            }
        }

        if (array_key_exists('location', $attributes)) {
            $attributes['location'] = $this->formatLocation($attributes['location']);
        }

        $expectedAggregateRevision = null;
        if (array_key_exists('aggregate_revision', $attributes)) {
            $expectedAggregateRevision = max(0, (int) $attributes['aggregate_revision']);
            unset($attributes['aggregate_revision']);
        }

        $normalizedNestedProfileGroups = $this->prepareNestedProfileGroupsForWrite(
            $profileType,
            $attributes,
            (string) $profile->getKey(),
        );

        $attributes = [
            ...$attributes,
            ...$this->contactChannelsService->normalizeForWrite(
                $profileType,
                $attributes,
                $profile,
            ),
        ];

        $profileId = (string) $profile->getKey();
        $commandId = $this->normalizeCommandId($commandId);
        $relationAttributes = array_key_exists('nested_profile_groups', $attributes)
            ? [...$attributes, 'nested_profile_groups' => $normalizedNestedProfileGroups ?? []]
            : $attributes;
        $fingerprint = $this->outboxPublisher->fingerprintForUpdate(
            $profileId,
            $relationAttributes,
            $fingerprintSupplement,
        );

        try {
            /** @var array{profile:AccountProfile,outbox_event_id:?string} $result */
            $result = $this->transactionRunner->run(
                function (AccountProfileTransactionContext $context) use (
                    $profileId,
                    $attributes,
                    $relationAttributes,
                    $normalizedNestedProfileGroups,
                    $commandId,
                    $fingerprint,
                    $mutateWithinTransaction,
                    $expectedAggregateRevision,
                    $useAggregateRevisionCas,
                ): array {
                    $receipt = $this->outboxPublisher->receipt($context, $commandId);
                    if ($receipt !== null) {
                        return $this->resultForCommandReceipt($receipt, $fingerprint);
                    }

                    $persistedProfile = AccountProfile::query()->findOrFail($profileId);
                    $this->lifecycleService->assertProfileMutationAllowed($persistedProfile, $context);
                    $persistedProfile->fill($attributes);
                    if (
                        ! $this->hasSemanticMutation($persistedProfile)
                        && ! array_key_exists('nested_profile_groups', $attributes)
                        && $mutateWithinTransaction === null
                    ) {
                        $admittedTargets = $this->relationAdmissionService->admit(
                            $context,
                            $profileId,
                            $relationAttributes,
                            touchTargets: false,
                        );
                        $contactSourceId = trim((string) ($relationAttributes['contact_source_account_profile_id'] ?? ''));
                        if ($contactSourceId !== '' && isset($admittedTargets[$contactSourceId])) {
                            $this->contactChannelsService->assertMirroredAdmissionStillValid(
                                $admittedTargets[$contactSourceId],
                                $relationAttributes,
                            );
                        }

                        $this->outboxPublisher->recordReceiptOnly(
                            $context,
                            $persistedProfile,
                            $commandId,
                            $fingerprint,
                        );

                        return [
                            'profile' => $persistedProfile,
                            'outbox_event_id' => null,
                        ];
                    }

                    $admittedTargets = $this->relationAdmissionService->admit(
                        $context,
                        $profileId,
                        $relationAttributes,
                    );
                    $contactSourceId = trim((string) ($relationAttributes['contact_source_account_profile_id'] ?? ''));
                    if ($contactSourceId !== '' && isset($admittedTargets[$contactSourceId])) {
                        $this->contactChannelsService->assertMirroredAdmissionStillValid(
                            $admittedTargets[$contactSourceId],
                            $relationAttributes,
                        );
                    }

                    try {
                        if ($mutateWithinTransaction !== null) {
                            $mutateWithinTransaction($persistedProfile, $context);
                        }

                        $persistedProfile = $useAggregateRevisionCas
                            ? $this->persistWithAggregateRevisionCas(
                                $context,
                                $persistedProfile,
                                $expectedAggregateRevision,
                            )
                            : $this->persistWithoutAggregateRevisionCas(
                                $context,
                                $persistedProfile,
                            );
                        if (array_key_exists('nested_profile_groups', $attributes)) {
                            $this->nestedGroupMemberStore->replaceAllGroupsWithinContext(
                                $context,
                                $persistedProfile,
                                $normalizedNestedProfileGroups ?? [],
                                $admittedTargets,
                            );
                        } else {
                            $this->nestedGroupMemberStore->materializeLegacyIfNeededWithinContext(
                                $context,
                                $persistedProfile,
                            );
                        }
                        $this->nestedPublicMembersProjectionService->rebuildForProfileWithinContext($context, $persistedProfile);
                    } catch (BulkWriteException|CommandException $exception) {
                        if ($this->isDuplicateKeyException($exception)) {
                            throw ValidationException::withMessages([
                                'slug' => ['Account profile slug already exists.'],
                            ]);
                        }

                        throw ValidationException::withMessages([
                            'account_profile' => ['Something went wrong when trying to update the account profile.'],
                        ]);
                    }

                    $persistedProfile = $persistedProfile->fresh();
                    $outboxEventId = $this->outboxPublisher->recordUpsert(
                        $context,
                        $persistedProfile,
                        $commandId,
                        $fingerprint,
                    );

                    return [
                        'profile' => $persistedProfile,
                        'outbox_event_id' => $outboxEventId,
                    ];
                },
                function () use ($commandId, $fingerprint): ?array {
                    $receipt = $this->outboxPublisher->committedReceipt($commandId);

                    return $receipt === null ? null : $this->resultForCommandReceipt($receipt, $fingerprint);
                },
            );
        } catch (AccountProfileCommandIndeterminateException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($compensateKnownRollback !== null) {
                try {
                    $compensateKnownRollback();
                } catch (\Throwable $compensationException) {
                    report($compensationException);
                }
            }

            throw $exception;
        }

        $profile = $result['profile'];
        if ($dispatchOutboxImmediately && $result['outbox_event_id'] !== null) {
            $this->outboxDispatcher->dispatchEvent($result['outbox_event_id']);
        }

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{profile:AccountProfile,outbox_event_id:?string}
     */
    public function resultForCommandReceipt(array $receipt, string $fingerprint): array
    {
        $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

        return [
            'profile' => AccountProfile::withTrashed()->findOrFail((string) $receipt['profile_id']),
            'outbox_event_id' => trim((string) ($receipt['outbox_event_id'] ?? '')) ?: null,
        ];
    }

    /** @return array{profile:AccountProfile,outbox_event_id:?string}|null */
    public function resultForCommand(
        AccountProfileTransactionContext $context,
        string $commandId,
        string $fingerprint,
    ): ?array {
        $receipt = $this->outboxPublisher->receipt($context, $commandId);

        return $receipt === null ? null : $this->resultForCommandReceipt($receipt, $fingerprint);
    }

    /** @return array{profile:AccountProfile,outbox_event_id:?string}|null */
    public function resultForCommittedCommand(string $commandId, string $fingerprint): ?array
    {
        $receipt = $this->outboxPublisher->committedReceipt($commandId);

        return $receipt === null ? null : $this->resultForCommandReceipt($receipt, $fingerprint);
    }

    public function dispatchOutboxEvent(?string $outboxEventId): void
    {
        if ($outboxEventId !== null) {
            $this->outboxDispatcher->dispatchEvent($outboxEventId);
        }
    }

    private function normalizeCommandId(?string $commandId): string
    {
        $commandId = trim((string) $commandId);

        return $commandId === '' ? (string) Str::uuid() : $commandId;
    }

    public function delete(AccountProfile $profile, ?string $commandId = null): void
    {
        $this->lifecycleService->delete($profile, $commandId);
    }

    public function restore(AccountProfile $profile, ?string $commandId = null): AccountProfile
    {
        return $this->lifecycleService->restore($profile, $commandId);
    }

    public function forceDelete(AccountProfile $profile, ?string $commandId = null): void
    {
        $this->lifecycleService->forceDelete($profile, $commandId);
    }

    /**
     * @param  array<int, string>  $addIds
     * @param  array<int, string>  $removeIds
     * @return array<string, mixed>
     */
    public function patchNestedGroupMembers(
        AccountProfile $profile,
        string $groupId,
        array $addIds,
        array $removeIds,
        ?string $commandId = null,
    ): array {
        $groups = $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        $group = $this->nestedGroupService->findGroupOrFail($groups, $groupId);
        $profileId = trim((string) $profile->getKey());
        foreach ($addIds as $candidateId) {
            if ($profileId !== '' && trim((string) $candidateId) === $profileId) {
                throw ValidationException::withMessages([
                    'nested_profile_groups' => ['A profile cannot link itself as a nested profile.'],
                ]);
            }
        }

        $existingIds = $this->nestedGroupMemberStore->groupMemberIds($profile, (string) $group['id']);
        $removeLookup = array_fill_keys($removeIds, true);
        $nextIds = array_values(array_filter(
            $existingIds,
            static fn (string $profileId): bool => $profileId !== '' && ! isset($removeLookup[$profileId]),
        ));
        $seen = array_fill_keys($nextIds, true);
        foreach ($addIds as $profileId) {
            if ($profileId === '' || isset($seen[$profileId])) {
                continue;
            }
            $nextIds[] = $profileId;
            $seen[$profileId] = true;
        }

        $updatedProfile = $this->update(
            $profile,
            [],
            $commandId,
            function (AccountProfile $persistedProfile, AccountProfileTransactionContext $context) use ($group, $addIds, $nextIds): void {
                $admittedTargets = [];
                if ($addIds !== []) {
                    $admittedTargets = $this->relationAdmissionService->admit(
                        $context,
                        (string) $persistedProfile->getKey(),
                        [
                            'nested_profile_groups' => [[
                                'account_profile_ids' => $addIds,
                            ]],
                        ],
                    );
                }

                $this->nestedGroupMemberStore->replaceGroupMembersWithinContext(
                    $context,
                    $persistedProfile,
                    (string) $group['id'],
                    $nextIds,
                    $admittedTargets,
                    $group,
                );
            },
            useAggregateRevisionCas: false,
        );
        $updatedGroups = $this->nestedGroupMemberStore->metadataGroups($updatedProfile);
        $updatedGroup = $this->nestedGroupService->findGroupOrFail($updatedGroups, (string) $group['id']);

        return [
            'id' => (string) $updatedGroup['id'],
            'label' => (string) $updatedGroup['label'],
            'order' => (int) ($updatedGroup['order'] ?? 0),
            'member_count' => max(0, (int) ($updatedGroup['member_count'] ?? count($updatedGroup['account_profile_ids'] ?? []))),
        ];
    }

    /**
     * @return array{nested_profile_groups:array<int, array<string, mixed>>}
     */
    public function createNestedGroup(
        AccountProfile $profile,
        string $label,
        ?string $commandId = null,
    ): array {
        $normalizedLabel = trim($label);
        if ($normalizedLabel === '') {
            throw ValidationException::withMessages([
                'label' => ['Nested profile group label is required.'],
            ]);
        }

        $existingGroups = $this->nestedGroupMemberStore->metadataGroups($profile);
        if (count($existingGroups) >= InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile groups exceed the configured limit.'],
            ]);
        }

        $nextGroups = [
            ...$existingGroups,
            [
                'id' => $this->nextNestedGroupId($existingGroups, $normalizedLabel),
                'label' => $normalizedLabel,
                'order' => count($existingGroups),
            ],
        ];

        $updatedProfile = $this->update(
            $profile,
            [
                'nested_profile_groups' => $nextGroups,
            ],
            $commandId,
            useAggregateRevisionCas: false,
        );

        return [
            'nested_profile_groups' => $this->nestedGroupMemberStore->metadataGroups($updatedProfile),
        ];
    }

    /** @return array{id:string,label:string,order:int,member_count:int} */
    public function renameNestedGroup(
        AccountProfile $profile,
        string $groupId,
        string $label,
        ?string $commandId = null,
    ): array {
        $groups = $this->nestedGroupMemberStore->metadataGroups($profile);
        $group = $this->nestedGroupService->findGroupOrFail($groups, $groupId);
        $label = trim($label);

        if ($label === '') {
            throw ValidationException::withMessages(['label' => ['Nested profile group label is required.']]);
        }

        if ($label === (string) $group['label']) {
            return $this->nestedGroupMutationResult($group);
        }

        $nextGroups = array_map(static fn (array $candidate): array => [
            'id' => trim((string) ($candidate['id'] ?? '')),
            'label' => trim((string) ($candidate['id'] ?? '')) === (string) $group['id']
                ? $label
                : trim((string) ($candidate['label'] ?? '')),
            'order' => (int) ($candidate['order'] ?? 0),
        ], $groups);

        $updated = $this->update($profile, ['nested_profile_groups' => $nextGroups], $commandId, useAggregateRevisionCas: false);
        $updatedGroup = $this->nestedGroupService->findGroupOrFail(
            $this->nestedGroupMemberStore->metadataGroups($updated),
            (string) $group['id'],
        );

        return $this->nestedGroupMutationResult($updatedGroup);
    }

    /** @param array<string,mixed> $group
     *  @return array{id:string,label:string,order:int,member_count:int} */
    private function nestedGroupMutationResult(array $group): array
    {
        return [
            'id' => (string) $group['id'],
            'label' => (string) $group['label'],
            'order' => (int) ($group['order'] ?? 0),
            'member_count' => max(0, (int) ($group['member_count'] ?? 0)),
        ];
    }

    /**
     * @return array{nested_profile_groups:array<int, array<string, mixed>>,deleted_group_id:string}
     */
    public function deleteNestedGroup(
        AccountProfile $profile,
        string $groupId,
        ?string $commandId = null,
    ): array {
        $existingGroups = $this->nestedGroupMemberStore->metadataGroups($profile);
        $group = $this->nestedGroupService->findGroupOrFail($existingGroups, $groupId);
        $memberIds = $this->nestedGroupMemberStore->groupMemberIds($profile, (string) $group['id']);
        if (count($memberIds) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_MEMBERS_MAX) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile group delete exceeds the approved member budget.'],
            ]);
        }

        $nextGroups = [];
        foreach ($existingGroups as $candidate) {
            if (trim((string) ($candidate['id'] ?? '')) === (string) $group['id']) {
                continue;
            }

            $nextGroups[] = [
                'id' => trim((string) ($candidate['id'] ?? '')),
                'label' => trim((string) ($candidate['label'] ?? '')),
                'order' => count($nextGroups),
            ];
        }

        $updatedProfile = $this->update(
            $profile,
            [
                'nested_profile_groups' => $nextGroups,
            ],
            $commandId,
            useAggregateRevisionCas: false,
        );

        return [
            'nested_profile_groups' => $this->nestedGroupMemberStore->metadataGroups($updatedProfile),
            'deleted_group_id' => (string) $group['id'],
        ];
    }

    private function assertNestedProfileGroupsAllowed(string $profileType, mixed $rawGroups): void
    {
        if ($this->registryService->hasNestedProfileGroups($profileType)) {
            return;
        }

        if ($this->nestedProfileGroupsPayloadIsEmpty($rawGroups)) {
            return;
        }

        throw ValidationException::withMessages([
            'nested_profile_groups' => ['Nested profile groups are not enabled for this profile type.'],
        ]);
    }

    private function nestedProfileGroupsPayloadIsEmpty(mixed $rawGroups): bool
    {
        if (! is_array($rawGroups)) {
            return true;
        }

        foreach ($rawGroups as $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }
            $label = trim((string) ($rawGroup['label'] ?? ''));
            $memberIds = $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [];
            if ($label !== '' || (is_array($memberIds) && $memberIds !== [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>|null
     */
    private function prepareNestedProfileGroupsForWrite(
        string $profileType,
        array &$payload,
        ?string $parentProfileId = null,
    ): ?array {
        if (! array_key_exists('nested_profile_groups', $payload)) {
            return null;
        }

        $this->assertNestedProfileGroupsAllowed(
            $profileType,
            $payload['nested_profile_groups']
        );
        $this->nestedGroupService->assertMetadataOnlyInput(
            $payload['nested_profile_groups']
        );
        $normalizedGroups = $this->nestedGroupService->normalizeMetadataForWrite(
            $payload['nested_profile_groups'],
        );
        $payload['nested_profile_groups'] = $normalizedGroups;

        return $normalizedGroups;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existingGroups
     */
    private function nextNestedGroupId(array $existingGroups, string $label): string
    {
        $usedIds = [];
        foreach ($existingGroups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId !== '') {
                $usedIds[$groupId] = true;
            }
        }

        $base = trim(Str::slug($label), '-_');
        if ($base === '') {
            $base = 'grupo';
        }

        $base = substr($base, 0, InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX);
        $base = rtrim($base, '-_');
        if ($base === '') {
            $base = 'grupo';
        }

        $candidate = $base;
        $suffix = 2;
        while (isset($usedIds[$candidate])) {
            $suffixText = '-'.$suffix;
            $prefixLength = max(1, InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX - strlen($suffixText));
            $candidate = rtrim(substr($base, 0, $prefixLength), '-_');
            if ($candidate === '') {
                $candidate = 'grupo';
            }
            $candidate .= $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function persistWithAggregateRevisionCas(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        ?int $expectedAggregateRevision = null,
    ): AccountProfile {
        $profileId = trim((string) $profile->getKey());
        if ($profileId === '') {
            throw new ConcurrencyConflictException('Account Profile aggregate id is required for a revision CAS.');
        }

        try {
            $objectId = new ObjectId($profileId);
        } catch (\Throwable) {
            throw new ConcurrencyConflictException('Account Profile aggregate id is invalid for a revision CAS.');
        }

        $expectedRevision = $expectedAggregateRevision ?? max(0, (int) $profile->getAttribute('aggregate_revision'));
        $profile->setAttribute('aggregate_revision', $expectedRevision + 1);
        if ($profile->isDirty('display_name') || trim((string) $profile->getAttribute('name_search_key')) === '') {
            $profile->setAttribute(
                'name_search_key',
                AccountProfileNameSearchKey::fromDisplayName((string) $profile->getAttribute('display_name')),
            );
        }
        $profile->setAttribute('updated_at', now());
        $dirty = $profile->getDirty();
        unset($dirty['_id']);
        if ($dirty === []) {
            return $profile;
        }

        $revisionFilter = ['aggregate_revision' => $expectedRevision];
        if ($expectedRevision === 0 || ($expectedAggregateRevision !== null && $expectedRevision === 1)) {
            $acceptedLegacyRevisions = [
                ['aggregate_revision' => $expectedRevision],
                ['aggregate_revision' => 0],
                ['aggregate_revision' => null],
                ['aggregate_revision' => ['$exists' => false]],
            ];
            $revisionFilter = [
                '$or' => array_values(array_map(
                    static fn (array $candidate): array => $candidate,
                    $acceptedLegacyRevisions,
                )),
            ];
        }
        $updated = $context->collection('account_profiles')->findOneAndUpdate(
            ['_id' => $objectId, ...$revisionFilter],
            ['$set' => $dirty],
            [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($updated === null) {
            throw new ConcurrencyConflictException('Account Profile aggregate revision changed during mutation.');
        }

        return AccountProfile::query()->findOrFail($profileId);
    }

    private function persistWithoutAggregateRevisionCas(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): AccountProfile {
        $profileId = trim((string) $profile->getKey());
        if ($profileId === '') {
            throw new ConcurrencyConflictException('Account Profile aggregate id is required for a non-CAS mutation.');
        }

        try {
            $objectId = new ObjectId($profileId);
        } catch (\Throwable) {
            throw new ConcurrencyConflictException('Account Profile aggregate id is invalid for a non-CAS mutation.');
        }

        if ($profile->isDirty('display_name') || trim((string) $profile->getAttribute('name_search_key')) === '') {
            $profile->setAttribute(
                'name_search_key',
                AccountProfileNameSearchKey::fromDisplayName((string) $profile->getAttribute('display_name')),
            );
        }
        $profile->setAttribute('updated_at', now());
        $dirty = $profile->getDirty();
        unset($dirty['_id'], $dirty['aggregate_revision']);

        $updated = $context->collection('account_profiles')->findOneAndUpdate(
            ['_id' => $objectId],
            [[
                '$set' => [
                    ...$dirty,
                    'aggregate_revision' => [
                        '$add' => [
                            ['$ifNull' => ['$aggregate_revision', 0]],
                            1,
                        ],
                    ],
                ],
            ]],
            [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if ($updated === null) {
            throw new ConcurrencyConflictException('Account Profile aggregate could not be updated.');
        }

        return AccountProfile::query()->findOrFail($profileId);
    }

    private function hasSemanticMutation(AccountProfile $profile): bool
    {
        $dirty = $profile->getDirty();
        unset(
            $dirty['_id'],
            $dirty['updated_by'],
            $dirty['updated_by_type'],
        );

        return $dirty !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'type' => 'Point',
            'coordinates' => [(float) $lng, (float) $lat],
        ];
    }

    private function isDuplicateKeyException(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'E11000');
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, string>
     */
    private function flattenTaxonomyTerms(array $terms): array
    {
        $flat = [];
        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }

            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type !== '' && $value !== '') {
                $flat[] = "{$type}:{$value}";
            }
        }

        return array_values(array_unique($flat));
    }
}
