<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Accounts\AccountPublicationStateService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Support\RichText\RichTextReadCanonicalizer;

class AccountProfileFormatterService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly AccountPublicationStateService $accountPublicationStateService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileAgendaOccurrencesService $agendaOccurrencesService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupMemberStore $nestedGroupMemberStore,
        private readonly AccountProfileGalleryService $galleryService,
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
        private readonly AccountProfileContactChannelsService $contactChannelsService,
        private readonly AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
        private readonly RichTextReadCanonicalizer $richTextReadCanonicalizer,
        private readonly AccountProfileExternalLinkService $externalLinks,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function format(
        AccountProfile $profile,
        bool $includeAgendaOccurrences = false,
        bool $publicContactProjection = false,
        bool $includeExternalLinks = false,
        bool $includeExternalLinksLimit = false,
        ?Account $account = null,
        bool $includeAdminVisibility = false,
    ): array {
        $baseUrl = request()->getSchemeAndHttpHost();
        $account ??= $profile->relationLoaded('account')
            ? $profile->getRelation('account')
            : Account::query()->where('_id', $profile->account_id)->first();
        $slug = trim((string) ($profile->slug ?? ''));
        $publicCatalogPolicy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        $canOpenPublicDetail = $publicCatalogPolicy->canOpenPublicDetail($profile, $account);

        $nestedProfileGroups = $includeAgendaOccurrences
            ? ($publicCatalogPolicy->isPublicNestedParent($profile, $account)
                ? $this->nestedGroupMemberStore->publicMetadataGroups($profile)
                : [])
            : $this->nestedGroupMemberStore->metadataGroups($profile);
        $selectedSummariesByProfileId = $includeAgendaOccurrences
            ? []
            : $this->contactSourceSelectedSummaries($profile);

        $payload = [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'aggregate_revision' => $includeAgendaOccurrences
                ? null
                : max(0, (int) ($profile->aggregate_revision ?? 0)),
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
            'avatar_url' => $publicCatalogPolicy->canExposePublicMedia($profile, $account, 'avatar')
                ? $this->mediaService->normalizePublicUrl(
                    $baseUrl,
                    $profile,
                    'avatar',
                    is_string($profile->avatar_url) ? $profile->avatar_url : null
                )
                : null,
            'cover_url' => $publicCatalogPolicy->canExposePublicMedia($profile, $account, 'cover')
                ? $this->mediaService->normalizePublicUrl(
                    $baseUrl,
                    $profile,
                    'cover',
                    is_string($profile->cover_url) ? $profile->cover_url : null
                )
                : null,
            'bio' => $this->canonicalRichText($profile, 'bio'),
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->ensureSnapshots(
                is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
            ),
            'gallery_groups' => $includeAgendaOccurrences
                ? $this->galleryService->formatForPublicDetail($profile, $baseUrl)
                : $this->galleryService->formatForRead($profile, $baseUrl),
            'nested_profile_groups' => $nestedProfileGroups,
            'ownership_state' => $account
                ? $this->ownershipStateService->deriveOwnershipState($account)
                : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
        ];

        if (! $publicContactProjection || $this->typeSetProvider->isLocationEnabled((string) $profile->profile_type)) {
            $payload['location'] = $this->formatLocation($profile->location);
        }

        if ($includeExternalLinks && $this->externalLinks->isAllowedForRead($profile)) {
            $formattedExternalLinks = $this->externalLinks->formatForRead($profile);
            if ($includeExternalLinksLimit || $formattedExternalLinks !== []) {
                $payload['external_links'] = $formattedExternalLinks;
            }
            if ($includeExternalLinksLimit) {
                $payload['external_links_limit'] = $this->externalLinks->currentLimit($profile);
            }
        }

        $payload = [
            ...$payload,
            ...($publicContactProjection
                ? $this->contactChannelsService->formatForPublicRead($profile)
                : $this->contactChannelsService->formatForRead($profile, $selectedSummariesByProfileId)),
        ];

        if ($includeAgendaOccurrences) {
            $payload['agenda_occurrences'] = $this->agendaOccurrencesService->forProfile($profile);
        }

        if ($includeAdminVisibility) {
            $payload = [
                ...$payload,
                'visibility' => $profile->visibility,
                'is_active' => (bool) $profile->is_active,
                'parent_account_publication_status' => $account instanceof Account
                    ? $this->accountPublicationStateService->normalizePublication($account->publication)['status']
                    : null,
                ...$this->adminMediaUrls($profile, $baseUrl),
            ];
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function adminMediaUrls(AccountProfile $profile, string $baseUrl): array
    {
        $profileType = (string) $profile->profile_type;
        $urls = [];

        foreach (['avatar' => $this->typeSetProvider->avatarEnabledTypes(), 'cover' => $this->typeSetProvider->coverEnabledTypes()] as $kind => $enabledTypes) {
            if (! in_array($profileType, $enabledTypes, true)
                || $this->mediaService->resolveMediaPathForBaseUrl($profile, $kind, $baseUrl) === null) {
                continue;
            }

            $urls['admin_'.$kind.'_url'] = rtrim($baseUrl, '/')
                .'/admin/api/v1/account_profiles/'.$profile->getKey().'/media/'.$kind;
        }

        return $urls;
    }

    private function canonicalRichText(AccountProfile $profile, string $field): string
    {
        return $this->richTextReadCanonicalizer->canonicalize(
            $profile->getAttribute($field),
            true,
            'account_profile',
            (string) $profile->getKey(),
            $field,
        );
    }

    /**
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function contactSourceSelectionIds(AccountProfile $profile): array
    {
        $ids = [];
        $contactSourceId = trim((string) ($profile->contact_source_account_profile_id ?? ''));
        if ($contactSourceId !== '') {
            $ids[$contactSourceId] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>
     */
    private function contactSourceSelectedSummaries(AccountProfile $profile): array
    {
        return $this->candidateDiscoveryService->selectedSummariesByIds(
            $this->contactSourceSelectionIds($profile),
        );
    }
}
