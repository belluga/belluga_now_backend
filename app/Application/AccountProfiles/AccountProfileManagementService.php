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
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Model\BSONArray;
use MongoDB\Operation\FindOneAndUpdate;

class AccountProfileManagementService
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfileNestedGroupMemberStore $nestedGroupMemberStore,
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
        $this->nestedGroupMemberStore->synchronizeGroupHeadsWithinContext(
            $context,
            $profile,
            $relationNestedGroups ?? [],
        );
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
            $payload = [
                ...$payload,
                ...AccountProfileSearchV1::fromSources(
                    (string) ($payload['display_name'] ?? ''),
                    $this->registryService->typeDefinition($profileType),
                    (array) ($payload['taxonomy_terms'] ?? []),
                ),
            ];

            $profile = AccountProfile::create($payload)->fresh();
        } catch (ValidationException $exception) {
            throw $exception;
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
        bool $forceSearchRefresh = false,
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
                    $forceSearchRefresh,
                ): array {
                    $receipt = $this->outboxPublisher->receipt($context, $commandId);
                    if ($receipt !== null) {
                        return $this->resultForCommandReceipt($receipt, $fingerprint);
                    }

                    $persistedProfile = AccountProfile::query()->findOrFail($profileId);
                    $this->lifecycleService->assertProfileMutationAllowed($persistedProfile, $context);
                    $this->nestedGroupMemberStore->assertCanonicalGroupHeadsAvailableWithinContext(
                        $context,
                        $persistedProfile,
                    );
                    $persistedProfile->fill($attributes);
                    if ($forceSearchRefresh) {
                        $this->applyCanonicalSearchFields($persistedProfile);
                    }
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
                            $this->nestedGroupMemberStore->synchronizeGroupHeadsWithinContext(
                                $context,
                                $persistedProfile,
                                $normalizedNestedProfileGroups ?? [],
                            );
                        }
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

    public function hasCommittedCommand(?string $commandId): bool
    {
        $normalized = trim((string) $commandId);

        return $normalized !== '' && $this->outboxPublisher->committedReceipt($normalized) !== null;
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
        $groups = $this->nestedGroupService->formatMetadataForRead($profile->nested_profile_groups ?? []);
        $group = $this->nestedGroupService->findGroupOrFail($groups, $groupId);
        $profileId = trim((string) $profile->getKey());
        foreach ($addIds as $candidateId) {
            if ($profileId !== '' && trim((string) $candidateId) === $profileId) {
                throw ValidationException::withMessages([
                    'nested_profile_groups' => ['A profile cannot link itself as a nested profile.'],
                ]);
            }
        }

        $updatedProfile = $this->update(
            $profile,
            [],
            $commandId,
            function (AccountProfile $persistedProfile, AccountProfileTransactionContext $context) use ($group, $addIds, $removeIds): void {
                $admittedTargets = [];
                if ($addIds !== []) {
                    $admittedTargets = $this->relationAdmissionService->admitQueryableProfiles(
                        $context,
                        (string) $persistedProfile->getKey(),
                        $addIds,
                    );
                }

                $this->nestedGroupMemberStore->patchGroupMembersWithinContext(
                    $context,
                    $persistedProfile,
                    (string) $group['id'],
                    $addIds,
                    $removeIds,
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
            'member_count' => max(0, (int) ($updatedGroup['member_count'] ?? 0)),
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

    /** @return array{id:string,label:string} */
    public function renameNestedGroup(
        AccountProfile $profile,
        string $groupId,
        string $label,
    ): array {
        $label = trim($label);

        if ($label === '') {
            throw ValidationException::withMessages(['label' => ['Nested profile group label is required.']]);
        }

        /** @var array{id:string,label:string,_changed:bool} $group */
        $group = $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use (
            $profile,
            $groupId,
            $label,
        ): array {
            $group = $this->nestedGroupMemberStore->renameGroupLabelWithinContext(
                $context,
                $profile,
                $groupId,
                $label,
            );
            if (! $group['_changed']) {
                return $group;
            }

            $this->lifecycleService->assertProfileMutationAllowed($profile, $context);
            try {
                $profileObjectId = new ObjectId((string) $profile->getKey());
            } catch (\Throwable) {
                throw new ConcurrencyConflictException('Account Profile aggregate id is invalid for nested group rename.');
            }
            $expectedFence = max(0, (int) $profile->getAttribute('lifecycle_fence_revision'));
            $fenceFilter = $expectedFence === 0
                ? ['$or' => [['lifecycle_fence_revision' => 0], ['lifecycle_fence_revision' => ['$exists' => false]]]]
                : ['lifecycle_fence_revision' => $expectedFence];
            $updated = $context->collection('account_profiles')->updateOne(
                [
                    '_id' => $profileObjectId,
                    'deleted_at' => null,
                    'account_profile_deletion_attempt_id' => null,
                    'nested_profile_groups.id' => (string) $group['id'],
                    ...$fenceFilter,
                ],
                [
                    '$set' => [
                        'nested_profile_groups.$[group].label' => $label,
                        'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                    ],
                    '$inc' => ['aggregate_revision' => 1],
                ],
                [...$context->rawOptions(), 'arrayFilters' => [['group.id' => (string) $group['id']]]],
            );
            if ($updated->getMatchedCount() !== 1 || $updated->getModifiedCount() !== 1) {
                throw new ConcurrencyConflictException('Account Profile nested group mirror changed during rename.');
            }

            return $group;
        });

        unset($group['_changed']);

        return $group;
    }

    /** @return array{account_profile_id:string,groups:array<int, array{id:string,order:int}>} */
    public function moveNestedGroup(
        AccountProfile $profile,
        string $groupId,
        string $direction,
    ): array {
        $profileId = trim((string) $profile->getKey());

        return $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use (
            $profile,
            $profileId,
            $groupId,
            $direction,
        ): array {
            $this->lifecycleService->assertProfileMutationAllowed($profile, $context);
            $groups = $this->nestedGroupMemberStore->orderedGroupPositionsWithinContext($context, $profile);
            $this->assertGroupOrderParity($groups, $profile->nested_profile_groups ?? [], 'Account Profile');
            $index = array_search(trim($groupId), array_column($groups, 'id'), true);
            if ($index === false) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
            }

            $neighborIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if (! isset($groups[$neighborIndex])) {
                return ['account_profile_id' => $profileId, 'groups' => $groups];
            }

            $moved = $groups[$index];
            $neighbor = $groups[$neighborIndex];
            $this->nestedGroupMemberStore->swapAdjacentGroupOrdersWithinContext($context, $profile, $moved, $neighbor);

            $expectedFence = max(0, (int) $profile->getAttribute('lifecycle_fence_revision'));
            $fenceFilter = $expectedFence === 0
                ? ['$or' => [['lifecycle_fence_revision' => 0], ['lifecycle_fence_revision' => ['$exists' => false]]]]
                : ['lifecycle_fence_revision' => $expectedFence];
            $updated = $context->collection('account_profiles')->updateOne([
                '_id' => new ObjectId($profileId),
                'deleted_at' => null,
                'account_profile_deletion_attempt_id' => null,
                'nested_profile_groups' => ['$all' => [
                    ['$elemMatch' => ['id' => $moved['id'], 'order' => $moved['order']]],
                    ['$elemMatch' => ['id' => $neighbor['id'], 'order' => $neighbor['order']]],
                ]],
                ...$fenceFilter,
            ], [
                '$set' => [
                    'nested_profile_groups.$[moved].order' => $neighbor['order'],
                    'nested_profile_groups.$[neighbor].order' => $moved['order'],
                    'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                ],
                '$inc' => ['aggregate_revision' => 1],
            ], [...$context->rawOptions(), 'arrayFilters' => [
                ['moved.id' => $moved['id'], 'moved.order' => $moved['order']],
                ['neighbor.id' => $neighbor['id'], 'neighbor.order' => $neighbor['order']],
            ]]);
            if ($updated->getMatchedCount() !== 1 || $updated->getModifiedCount() !== 1) {
                throw new ConcurrencyConflictException('Account Profile nested group mirror changed during reorder.');
            }

            $groups[$index]['order'] = $neighbor['order'];
            $groups[$neighborIndex]['order'] = $moved['order'];
            usort($groups, static fn (array $left, array $right): int => [$left['order'], $left['id']] <=> [$right['order'], $right['id']]);

            return ['account_profile_id' => $profileId, 'groups' => array_values($groups)];
        });
    }

    /**
     * @return array{nested_profile_groups:array<int, array<string, mixed>>,deleted_group_id:string}
     */
    public function deleteNestedGroup(
        AccountProfile $profile,
        string $groupId,
        ?string $commandId = null,
    ): array {
        $profileId = (string) $profile->getKey();
        $result = $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($profileId, $groupId): array {
            $persisted = AccountProfile::query()->findOrFail($profileId);
            $this->lifecycleService->assertProfileMutationAllowed($persisted, $context);
            $groups = $this->nestedGroupMemberStore->metadataGroupsWithinContext($context, $persisted);
            $group = $this->nestedGroupService->findGroupOrFail($groups, $groupId);
            $this->nestedGroupMemberStore->deleteGroupWithinContext($context, $persisted, (string) $group['id']);
            $nextGroups = array_values(array_map(
                static function (array $candidate) use ($group): array {
                    if ((int) ($candidate['order'] ?? 0) > (int) ($group['order'] ?? 0)) {
                        $candidate['order'] = (int) $candidate['order'] - 1;
                    }

                    return $candidate;
                },
                array_values(array_filter(
                    $groups,
                    static fn (array $candidate): bool => (string) ($candidate['id'] ?? '') !== (string) $group['id'],
                )),
            ));
            $updated = $context->collection('account_profiles')->updateOne(
                ['_id' => new ObjectId($profileId), 'deleted_at' => null, 'nested_profile_groups.id' => (string) $group['id']],
                ['$set' => ['nested_profile_groups' => $nextGroups], '$inc' => ['aggregate_revision' => 1]],
                $context->rawOptions(),
            );
            if ($updated->getMatchedCount() !== 1) {
                throw new ConcurrencyConflictException('Account Profile nested group mirror changed during delete.');
            }

            return ['group' => $group, 'profile' => $persisted->fresh() ?? $persisted];
        });

        return [
            'nested_profile_groups' => $this->nestedGroupMemberStore->metadataGroups($result['profile']),
            'deleted_group_id' => (string) $result['group']['id'],
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

    /**
     * @param  array<int, array{id:string,order:int}>  $heads
     */
    private function assertGroupOrderParity(array $heads, mixed $rawMirror, string $owner): void
    {
        if ($rawMirror instanceof BSONArray) {
            $rawMirror = $rawMirror->getArrayCopy();
        }
        if (! is_array($rawMirror)) {
            throw new ConcurrencyConflictException("{$owner} nested group mirror is malformed.");
        }

        $mirror = [];
        foreach ($rawMirror as $group) {
            if ($group instanceof \MongoDB\Model\BSONDocument) {
                $group = $group->getArrayCopy();
            }
            if (! is_array($group)) {
                throw new ConcurrencyConflictException("{$owner} nested group mirror is malformed.");
            }
            $mirror[] = ['id' => trim((string) ($group['id'] ?? $group['_id'] ?? '')), 'order' => (int) ($group['order'] ?? -1)];
        }
        usort($mirror, static fn (array $left, array $right): int => [$left['order'], $left['id']] <=> [$right['order'], $right['id']]);

        if ($heads === [] && $mirror === []) {
            return;
        }

        $ids = array_column($heads, 'id');
        $orders = array_column($heads, 'order');
        if ($ids === [] || count($ids) !== count(array_unique($ids)) || $orders !== range(0, count($heads) - 1) || $mirror !== $heads) {
            throw new ConcurrencyConflictException("{$owner} nested group order is inconsistent.");
        }
    }

    private function nestedProfileGroupsPayloadIsEmpty(mixed $rawGroups): bool
    {
        if (! is_array($rawGroups)) {
            return true;
        }

        return $rawGroups === [];
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
        $this->applyCanonicalSearchFields($profile);
        $searchChanged = $profile->isDirty('name_search_key') || $profile->isDirty('search_terms');
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

        if ($searchChanged) {
            $this->nestedGroupMemberStore->refreshSearchForMemberWithinContext(
                $context,
                $profileId,
                (string) ($updated['name_search_key'] ?? ''),
                ($updated['search_terms'] ?? null) instanceof BSONArray
                    ? $updated['search_terms']->getArrayCopy()
                    : (array) ($updated['search_terms'] ?? []),
            );
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

        $this->applyCanonicalSearchFields($profile);
        $searchChanged = $profile->isDirty('name_search_key') || $profile->isDirty('search_terms');
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

        if ($searchChanged) {
            $this->nestedGroupMemberStore->refreshSearchForMemberWithinContext(
                $context,
                $profileId,
                (string) ($updated['name_search_key'] ?? ''),
                ($updated['search_terms'] ?? null) instanceof BSONArray
                    ? $updated['search_terms']->getArrayCopy()
                    : (array) ($updated['search_terms'] ?? []),
            );
        }

        return AccountProfile::query()->findOrFail($profileId);
    }

    private function applyCanonicalSearchFields(AccountProfile $profile): void
    {
        $profileType = (string) $profile->getAttribute('profile_type');
        $search = AccountProfileSearchV1::fromSources(
            (string) $profile->getAttribute('display_name'),
            $this->registryService->typeDefinition($profileType),
            (array) ($profile->getAttribute('taxonomy_terms') ?? []),
        );
        $profile->setAttribute('name_search_key', $search['name_search_key']);
        $profile->setAttribute('search_terms', $search['search_terms']);
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
