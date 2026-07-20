<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Application\Taxonomies\TaxonomyValidationService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;

class AccountProfileManagementService
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly TaxonomyValidationService $taxonomyValidationService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
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

        $profile = $this->createWithinCurrentTransaction(
            [...$payload, 'aggregate_revision' => 1],
            $context,
        );
        $relationAttributes = [
            'nested_profile_groups' => $profile->nested_profile_groups,
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
        if ($mutateWithinTransaction !== null) {
            $mutateWithinTransaction($profile);
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

        if (array_key_exists('nested_profile_groups', $payload)) {
            $this->assertNestedProfileGroupsAllowed(
                $profileType,
                $payload['nested_profile_groups']
            );
            $payload['nested_profile_groups'] = $this->nestedGroupService->normalizeForWrite(
                $payload['nested_profile_groups']
            );
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

            $profile = AccountProfile::create($payload)->fresh();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
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

        if (array_key_exists('nested_profile_groups', $attributes)) {
            $this->assertNestedProfileGroupsAllowed(
                $profileType,
                $attributes['nested_profile_groups']
            );
            $attributes['nested_profile_groups'] = $this->nestedGroupService->normalizeForWrite(
                $attributes['nested_profile_groups'],
                (string) $profile->getKey()
            );
        }

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
        $fingerprint = $this->outboxPublisher->fingerprintForUpdate(
            $profileId,
            $attributes,
            $fingerprintSupplement,
        );

        try {
            /** @var array{profile:AccountProfile,outbox_event_id:?string} $result */
            $result = $this->transactionRunner->run(
                function (AccountProfileTransactionContext $context) use (
                    $profileId,
                    $attributes,
                    $commandId,
                    $fingerprint,
                    $mutateWithinTransaction,
                ): array {
                    $receipt = $this->outboxPublisher->receipt($context, $commandId);
                    if ($receipt !== null) {
                        return $this->resultForCommandReceipt($receipt, $fingerprint);
                    }

                    $persistedProfile = AccountProfile::query()->findOrFail($profileId);
                    $this->lifecycleService->assertProfileMutationAllowed($persistedProfile, $context);
                    $persistedProfile->fill($attributes);
                    if (! $persistedProfile->isDirty() && $mutateWithinTransaction === null) {
                        $admittedTargets = $this->relationAdmissionService->admit(
                            $context,
                            $profileId,
                            $attributes,
                            touchTargets: false,
                        );
                        $contactSourceId = trim((string) ($attributes['contact_source_account_profile_id'] ?? ''));
                        if ($contactSourceId !== '' && isset($admittedTargets[$contactSourceId])) {
                            $this->contactChannelsService->assertMirroredAdmissionStillValid(
                                $admittedTargets[$contactSourceId],
                                $attributes,
                            );
                        }

                        return [
                            'profile' => $persistedProfile,
                            'outbox_event_id' => null,
                        ];
                    }

                    $admittedTargets = $this->relationAdmissionService->admit(
                        $context,
                        $profileId,
                        $attributes,
                    );
                    $contactSourceId = trim((string) ($attributes['contact_source_account_profile_id'] ?? ''));
                    if ($contactSourceId !== '' && isset($admittedTargets[$contactSourceId])) {
                        $this->contactChannelsService->assertMirroredAdmissionStillValid(
                            $admittedTargets[$contactSourceId],
                            $attributes,
                        );
                    }

                    try {
                        if ($mutateWithinTransaction !== null) {
                            $mutateWithinTransaction($persistedProfile);
                        }

                        $persistedProfile = $this->persistWithAggregateRevisionCas(
                            $context,
                            $persistedProfile,
                        );
                    } catch (BulkWriteException $exception) {
                        if (str_contains($exception->getMessage(), 'E11000')) {
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
        int $aggregateRevision,
        array $addIds,
        array $removeIds,
        ?string $commandId = null,
    ): array {
        $groups = $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        $group = $this->nestedGroupService->findGroupOrFail($groups, $groupId);

        $existingIds = array_values(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $group['account_profile_ids'] ?? [],
        ));
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

        $patchedGroups = array_map(
            function (array $candidateGroup) use ($group, $nextIds): array {
                if ((string) ($candidateGroup['id'] ?? '') !== (string) $group['id']) {
                    return $candidateGroup;
                }

                return [
                    'id' => (string) $candidateGroup['id'],
                    'label' => (string) $candidateGroup['label'],
                    'order' => (int) ($candidateGroup['order'] ?? 0),
                    'account_profile_ids' => $nextIds,
                    'member_count' => count($nextIds),
                ];
            },
            $groups,
        );

        $updatedProfile = $this->update(
            $profile,
            [
                'aggregate_revision' => $aggregateRevision,
                'nested_profile_groups' => $patchedGroups,
            ],
            $commandId,
        );
        $updatedGroups = $this->nestedGroupService->formatForRead($updatedProfile->nested_profile_groups ?? []);
        $updatedGroup = $this->nestedGroupService->findGroupOrFail($updatedGroups, (string) $group['id']);

        return [
            'id' => (string) $updatedGroup['id'],
            'label' => (string) $updatedGroup['label'],
            'order' => (int) ($updatedGroup['order'] ?? 0),
            'member_count' => max(0, (int) ($updatedGroup['member_count'] ?? count($updatedGroup['account_profile_ids'] ?? []))),
            'aggregate_revision' => max(0, (int) ($updatedProfile->aggregate_revision ?? 0)),
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

    private function persistWithAggregateRevisionCas(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
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

        $expectedRevision = max(0, (int) $profile->getAttribute('aggregate_revision'));
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
        if ($expectedRevision === 0) {
            $revisionFilter = [
                '$or' => [
                    ['aggregate_revision' => 0],
                    ['aggregate_revision' => ['$exists' => false]],
                ],
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
