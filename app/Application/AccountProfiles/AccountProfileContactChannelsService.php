<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Belluga\ContactChannels\ContactChannelCollectionNormalizer;
use Belluga\ContactChannels\ContactChannelNormalizationResult;
use Belluga\ContactChannels\ContactChannelValidationException;
use Belluga\ContactChannels\Registry\ContactChannelDefinitionRegistry;
use Illuminate\Validation\ValidationException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileContactChannelsService
{
    public const CONTACT_MODE_OWN = 'own';

    public const CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE = 'mirrored_account_profile';

    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
        private readonly AccountProfileTypeCapabilityCatalog $capabilityCatalog,
        private readonly AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
        private readonly ContactChannelDefinitionRegistry $definitionRegistry,
        private readonly ContactChannelCollectionNormalizer $collectionNormalizer,
    ) {}

    /**
     * The host owns profile persistence, tenant-local source lookup, mirror topology,
     * and the atomically persisted bubble pointer. The package owns channel data rules.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizeForWrite(
        string $profileType,
        array $payload,
        ?AccountProfile $currentProfile = null,
    ): array {
        $capabilityEnabled = $this->hasContactChannelsCapability($profileType);
        $stored = $currentProfile ? $this->readStoredContactState($currentProfile) : $this->emptyStoredContactState();

        // An unrelated PATCH must not replay contact fields from a stale model
        // snapshot. Mongo/Eloquent dirty tracking can then persist only the
        // requested unrelated fields, while an explicit contact mutation still
        // owns the collection and bubble pointer atomically.
        if ($currentProfile !== null
            && ! array_key_exists('profile_type', $payload)
            && ! $this->payloadTouchesContactState($payload)) {
            return [];
        }

        if (! $capabilityEnabled) {
            if ($this->payloadCarriesExplicitContactMutation($payload)) {
                throw ValidationException::withMessages([
                    'contact_channels' => ['Contact channels are not enabled for this profile type.'],
                ]);
            }

            return $this->emptyStoredContactState();
        }

        $mode = $this->normalizeMode(
            array_key_exists('contact_mode', $payload)
                ? $payload['contact_mode']
                : $stored['contact_mode'],
        );

        if ($mode === self::CONTACT_MODE_OWN) {
            $normalized = array_key_exists('contact_channels', $payload)
                ? $this->normalizeChannelsForWrite($payload['contact_channels'], $stored['contact_channels'])
                : new ContactChannelNormalizationResult($stored['contact_channels'], []);
            $bubbleChannelId = $this->resolveBubbleSelectionForWrite(
                $payload,
                $stored['contact_bubble_channel_id'],
                $normalized->draftKeyToChannelId,
            );

            if ($bubbleChannelId !== null && $this->payloadRequiresBubbleValidation($payload)) {
                $this->assertBubbleSelectionValid($bubbleChannelId, $normalized->channels);
            }

            return [
                'contact_mode' => self::CONTACT_MODE_OWN,
                'contact_source_account_profile_id' => null,
                'contact_channels' => $normalized->channels,
                'contact_bubble_channel_id' => $normalized->channels === [] ? null : $bubbleChannelId,
            ];
        }

        if (array_key_exists('contact_channels', $payload) && ! $this->rawChannelsAreEmpty($payload['contact_channels'])) {
            throw ValidationException::withMessages([
                'contact_channels' => ['Mirrored contact profiles cannot author local contact channels.'],
            ]);
        }

        $sourceId = array_key_exists('contact_source_account_profile_id', $payload)
            ? $this->normalizeNullableString($payload['contact_source_account_profile_id'])
            : $stored['contact_source_account_profile_id'];
        if ($sourceId === null) {
            throw ValidationException::withMessages([
                'contact_source_account_profile_id' => ['A mirrored contact source profile is required.'],
            ]);
        }

        $currentProfileId = $currentProfile ? trim((string) $currentProfile->getKey()) : null;
        if ($currentProfileId !== null && $currentProfileId !== '' && $sourceId === $currentProfileId) {
            throw ValidationException::withMessages([
                'contact_source_account_profile_id' => ['A profile cannot mirror its own contact configuration.'],
            ]);
        }

        $bubbleChannelId = $this->resolveBubbleSelectionForWrite(
            $payload,
            $stored['contact_bubble_channel_id'],
            [],
        );

        return [
            'contact_mode' => self::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE,
            'contact_source_account_profile_id' => $sourceId,
            'contact_channels' => [],
            'contact_bubble_channel_id' => $bubbleChannelId,
        ];
    }

    /** @return array<string, mixed> */
    public function formatForRead(AccountProfile $profile, array $selectedSummariesByProfileId = []): array
    {
        $stored = $this->readStoredContactState($profile);
        $mode = $this->normalizeMode($stored['contact_mode']);
        $directSource = $mode === self::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE
            ? $this->resolveSameTenantOwnContactSource($stored['contact_source_account_profile_id'])
            : null;
        $effectiveSource = $this->resolveEffectiveContactSourceProfile($profile);
        $effectiveChannels = $this->resolveEffectiveContactChannels($profile);

        return [
            'contact_mode' => $mode,
            'contact_source_account_profile_id' => $stored['contact_source_account_profile_id'],
            'contact_source_account_profile' => $this->selectedSummary(
                $stored['contact_source_account_profile_id'],
                $selectedSummariesByProfileId,
            ),
            'contact_channels' => $stored['contact_channels'],
            'contact_bubble_channel_id' => $stored['contact_bubble_channel_id'],
            'effective_contact_source' => $this->profileSummary($effectiveSource),
            'effective_contact_channels' => $effectiveChannels,
            'effective_contact_bubble_channel' => $this->resolveBubbleChannel(
                $stored['contact_bubble_channel_id'],
                $effectiveChannels,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function formatForPublicRead(AccountProfile $profile): array
    {
        if (! $this->hasContactChannelsCapability((string) $profile->profile_type)) {
            return [
                'effective_contact_source' => null,
                'effective_contact_channels' => [],
                'effective_contact_bubble_channel' => null,
            ];
        }

        $stored = $this->readStoredContactState($profile);
        $effectiveChannels = $this->resolveEffectiveContactChannels($profile);

        return [
            'contact_mode' => $this->normalizeMode($stored['contact_mode']),
            'effective_contact_source' => $this->profileSummary(
                $this->resolveEffectiveContactSourceProfile($profile),
            ),
            'effective_contact_channels' => $effectiveChannels,
            'effective_contact_bubble_channel' => $this->resolveBubbleChannel(
                $stored['contact_bubble_channel_id'],
                $effectiveChannels,
            ),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function resolveEffectiveContactChannels(AccountProfile $profile): array
    {
        if (! $this->hasContactChannelsCapability((string) $profile->profile_type)) {
            return [];
        }

        $stored = $this->readStoredContactState($profile);
        if ($this->normalizeMode($stored['contact_mode']) === self::CONTACT_MODE_OWN) {
            return $stored['contact_channels'];
        }

        $source = $this->resolveSameTenantOwnContactSource($stored['contact_source_account_profile_id']);

        return $source ? $this->readStoredContactState($source)['contact_channels'] : [];
    }

    public function resolveEffectiveContactSourceProfile(AccountProfile $profile): ?AccountProfile
    {
        if (! $this->hasContactChannelsCapability((string) $profile->profile_type)) {
            return null;
        }

        $stored = $this->readStoredContactState($profile);
        if ($this->normalizeMode($stored['contact_mode']) === self::CONTACT_MODE_OWN) {
            return $profile;
        }

        return $this->resolveSameTenantOwnContactSource($stored['contact_source_account_profile_id']);
    }

    /**
     * Rechecks the source snapshot selected before the aggregate transaction.
     * The admission gateway has already fenced this exact source document.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assertMirroredAdmissionStillValid(AccountProfile $source, array $attributes): void
    {
        $stored = $this->readStoredContactState($source);
        if ($this->normalizeMode($stored['contact_mode']) !== self::CONTACT_MODE_OWN) {
            throw ValidationException::withMessages([
                'contact_source_account_profile_id' => ['Mirrored contact source must be an available own-mode profile in this tenant.'],
            ]);
        }

        $bubbleChannelId = $this->normalizeNullableString($attributes['contact_bubble_channel_id'] ?? null);
        if ($bubbleChannelId !== null) {
            $this->assertBubbleSelectionValid($bubbleChannelId, $stored['contact_channels']);
        }
    }

    /** @return array<string, mixed> */
    private function emptyStoredContactState(): array
    {
        return [
            'contact_mode' => self::CONTACT_MODE_OWN,
            'contact_source_account_profile_id' => null,
            'contact_channels' => [],
            'contact_bubble_channel_id' => null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadCarriesExplicitContactMutation(array $payload): bool
    {
        return array_key_exists('contact_mode', $payload)
            || array_key_exists('contact_source_account_profile_id', $payload)
            || array_key_exists('contact_bubble_channel_id', $payload)
            || array_key_exists('contact_bubble_channel_draft_key', $payload)
            || (array_key_exists('contact_channels', $payload) && ! $this->rawChannelsAreEmpty($payload['contact_channels']));
    }

    /** @param array<string, mixed> $payload */
    private function payloadTouchesContactState(array $payload): bool
    {
        return array_key_exists('contact_mode', $payload)
            || array_key_exists('contact_source_account_profile_id', $payload)
            || array_key_exists('contact_bubble_channel_id', $payload)
            || array_key_exists('contact_bubble_channel_draft_key', $payload)
            || array_key_exists('contact_channels', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function payloadRequiresBubbleValidation(array $payload): bool
    {
        return array_key_exists('contact_channels', $payload)
            || array_key_exists('contact_bubble_channel_id', $payload)
            || array_key_exists('contact_bubble_channel_draft_key', $payload)
            || array_key_exists('contact_mode', $payload)
            || array_key_exists('contact_source_account_profile_id', $payload);
    }

    private function rawChannelsAreEmpty(mixed $raw): bool
    {
        foreach ($this->arrayValues($raw) as $entry) {
            $channel = $this->arrayValues($entry);
            if ($this->normalizeNullableString($channel['type'] ?? null) !== null
                || $this->normalizeNullableString($channel['value'] ?? null) !== null
                || $this->normalizeNullableString($channel['title'] ?? null) !== null
                || $this->normalizeNullableString($channel['id'] ?? null) !== null
                || $this->normalizeNullableString($channel['draft_key'] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function readStoredContactState(AccountProfile $profile): array
    {
        return [
            'contact_mode' => $this->normalizeMode($profile->contact_mode ?? null),
            'contact_source_account_profile_id' => $this->normalizeNullableString($profile->contact_source_account_profile_id ?? null),
            'contact_channels' => $this->normalizeStoredChannels($profile->contact_channels ?? []),
            'contact_bubble_channel_id' => $this->normalizeNullableString($profile->contact_bubble_channel_id ?? null),
        ];
    }

    private function normalizeMode(mixed $raw): string
    {
        return strtolower(trim((string) $raw)) === self::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE
            ? self::CONTACT_MODE_MIRRORED_ACCOUNT_PROFILE
            : self::CONTACT_MODE_OWN;
    }

    private function normalizeNullableString(mixed $raw): ?string
    {
        $normalized = trim((string) $raw);

        return $normalized === '' ? null : $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeStoredChannels(mixed $raw): array
    {
        try {
            $channels = $this->plainArray($raw);

            return $this->collectionNormalizer->normalizeForWrite($channels, $channels)->channels;
        } catch (ContactChannelValidationException|\RuntimeException) {
            return [];
        }
    }

    private function normalizeChannelsForWrite(mixed $raw, array $stored): ContactChannelNormalizationResult
    {
        if (! is_array($raw) && ! $raw instanceof BSONArray && ! $raw instanceof BSONDocument && ! $raw instanceof \Traversable) {
            throw ValidationException::withMessages([
                'contact_channels' => ['Contact channels must be an array.'],
            ]);
        }

        try {
            return $this->collectionNormalizer->normalizeForWrite($this->plainArray($raw), $stored);
        } catch (ContactChannelValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * `contact_bubble_channel_id` carries either an existing persistent id or
     * explicit null to clear. `contact_bubble_channel_draft_key` selects a new
     * request-local channel and is translated before persistence. Omitting both
     * preserves the pointer for unrelated PATCH requests.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $draftKeyToChannelId
     */
    private function resolveBubbleSelectionForWrite(
        array $payload,
        ?string $storedBubbleChannelId,
        array $draftKeyToChannelId,
    ): ?string {
        $hasId = array_key_exists('contact_bubble_channel_id', $payload);
        $hasDraftKey = array_key_exists('contact_bubble_channel_draft_key', $payload);
        if ($hasId && $hasDraftKey) {
            throw ValidationException::withMessages([
                'contact_bubble_channel_id' => ['Select a bubble channel by persisted id or draft key, not both.'],
            ]);
        }
        if ($hasId) {
            return $this->normalizeNullableString($payload['contact_bubble_channel_id']);
        }
        if (! $hasDraftKey) {
            return $storedBubbleChannelId;
        }

        $draftKey = $this->normalizeNullableString($payload['contact_bubble_channel_draft_key']);
        if ($draftKey === null || ! isset($draftKeyToChannelId[$draftKey])) {
            throw ValidationException::withMessages([
                'contact_bubble_channel_draft_key' => ['The selected bubble draft key must reference a new channel in this request.'],
            ]);
        }

        return $draftKeyToChannelId[$draftKey];
    }

    /** @param array<int, array<string, mixed>> $channels */
    private function assertBubbleSelectionValid(string $bubbleChannelId, array $channels): void
    {
        foreach ($channels as $channel) {
            if (($channel['id'] ?? null) !== $bubbleChannelId) {
                continue;
            }

            try {
                if ($this->definitionRegistry->require((string) ($channel['type'] ?? ''))->capabilities()->bubble) {
                    return;
                }
            } catch (ContactChannelValidationException) {
                // Stored/corrupted data cannot become bubble-eligible.
            }
        }

        throw ValidationException::withMessages([
            'contact_bubble_channel_id' => ['The selected contact bubble channel must reference a valid bubble-eligible contact channel.'],
        ]);
    }

    private function resolveSameTenantOwnContactSource(?string $sourceId): ?AccountProfile
    {
        if ($sourceId === null || $sourceId === '') {
            return null;
        }

        $profile = $this->candidateDiscoveryService
            ->eligibleProfilesByIds(
                AccountProfileCandidateDiscoveryService::SCOPE_CONTACT_CAPABLE,
                [$sourceId],
            )
            ->first();

        return $profile instanceof AccountProfile ? $profile : null;
    }

    private function hasContactChannelsCapability(string $profileType): bool
    {
        $definition = $this->registryService->typeDefinition($profileType);
        $capabilities = is_array($definition['capabilities'] ?? null)
            ? $definition['capabilities']
            : [];

        return $this->capabilityCatalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_CONTACT_CHANNELS,
            $capabilities,
        );
    }

    /** @return array<string, mixed>|null */
    private function profileSummary(?AccountProfile $profile): ?array
    {
        if (! $profile instanceof AccountProfile) {
            return null;
        }

        return [
            'id' => (string) $profile->getKey(),
            'display_name' => trim((string) $profile->display_name),
            'slug' => $this->normalizeNullableString($profile->slug ?? null),
            'profile_type' => trim((string) $profile->profile_type),
        ];
    }

    /**
     * @param  array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>  $selectedSummariesByProfileId
     * @return array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}|null
     */
    private function selectedSummary(?string $profileId, array $selectedSummariesByProfileId): ?array
    {
        if ($profileId === null) {
            return null;
        }

        return $selectedSummariesByProfileId[$profileId] ?? [
            'id' => $profileId,
            'display_name' => null,
            'is_queryable_candidate' => false,
            'is_contact_capable_candidate' => false,
        ];
    }

    /** @param array<int, array<string, mixed>> $channels @return array<string, mixed>|null */
    private function resolveBubbleChannel(?string $selectedId, array $channels): ?array
    {
        if ($selectedId === null) {
            return null;
        }

        foreach ($channels as $channel) {
            if (($channel['id'] ?? null) !== $selectedId) {
                continue;
            }
            try {
                if ($this->definitionRegistry->require((string) ($channel['type'] ?? ''))->capabilities()->bubble) {
                    return $channel;
                }
            } catch (ContactChannelValidationException) {
                return null;
            }
        }

        return null;
    }

    /** @return array<int|string, mixed> */
    private function arrayValues(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw instanceof BSONArray || $raw instanceof BSONDocument) {
            return $raw->getArrayCopy();
        }
        if ($raw instanceof \Traversable) {
            return iterator_to_array($raw);
        }

        return [];
    }

    /** @return array<int|string, mixed> */
    private function plainArray(mixed $raw): array
    {
        return $this->plainValue($raw);
    }

    private function plainValue(mixed $value): mixed
    {
        if (is_array($value) || $value instanceof BSONArray || $value instanceof BSONDocument || $value instanceof \Traversable) {
            $result = [];
            foreach ($this->arrayValues($value) as $key => $item) {
                $result[$key] = $this->plainValue($item);
            }

            return $result;
        }

        return $value;
    }
}
