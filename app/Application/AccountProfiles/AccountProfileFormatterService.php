<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;

class AccountProfileFormatterService
{
    public function __construct(
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly AccountProfileMediaService $mediaService,
        private readonly AccountProfileAgendaOccurrencesService $agendaOccurrencesService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfileGalleryService $galleryService,
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
        private readonly AccountProfileContactChannelsService $contactChannelsService,
        private readonly AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function format(
        AccountProfile $profile,
        bool $includeAgendaOccurrences = false,
        bool $publicContactProjection = false,
    ): array {
        $baseUrl = request()->getSchemeAndHttpHost();
        $account = Account::query()->where('_id', $profile->account_id)->first();
        $slug = trim((string) ($profile->slug ?? ''));
        $publicCatalogPolicy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        $canOpenPublicDetail = $publicCatalogPolicy->canOpenPublicDetail($profile);

        $nestedProfileGroups = $includeAgendaOccurrences
            ? $this->nestedGroupService->formatForPublicDetail($profile, $baseUrl, $publicCatalogPolicy)
            : $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        $selectedSummariesByProfileId = $includeAgendaOccurrences
            ? []
            : $this->candidateDiscoveryService->selectedSummariesByIds(
                $this->linkedSelectionIds($nestedProfileGroups, $profile),
            );

        $payload = [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
            'avatar_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'avatar',
                is_string($profile->avatar_url) ? $profile->avatar_url : null
            ),
            'cover_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'cover',
                is_string($profile->cover_url) ? $profile->cover_url : null
            ),
            'bio' => $profile->bio,
            'content' => $profile->content,
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->ensureSnapshots(
                is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
            ),
            'gallery_groups' => $includeAgendaOccurrences
                ? $this->galleryService->formatForPublicDetail($profile, $baseUrl)
                : $this->galleryService->formatForRead($profile, $baseUrl),
            'nested_profile_groups' => $includeAgendaOccurrences
                ? $nestedProfileGroups
                : $this->nestedGroupService->withSelectedSummaries(
                    $nestedProfileGroups,
                    $selectedSummariesByProfileId,
                ),
            'location' => $this->formatLocation($profile->location),
            'ownership_state' => $account
                ? $this->ownershipStateService->deriveOwnershipState($account)
                : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
        ];

        $payload = [
            ...$payload,
            ...($publicContactProjection
                ? $this->contactChannelsService->formatForPublicRead($profile)
                : $this->contactChannelsService->formatForRead($profile, $selectedSummariesByProfileId)),
        ];

        if ($includeAgendaOccurrences) {
            $payload['agenda_occurrences'] = $this->agendaOccurrencesService->forProfile($profile);
        }

        return $payload;
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
     * @param  array<int, array<string, mixed>>  $nestedProfileGroups
     * @return array<int, string>
     */
    private function linkedSelectionIds(array $nestedProfileGroups, AccountProfile $profile): array
    {
        $ids = [];
        foreach ($nestedProfileGroups as $group) {
            foreach ($group['account_profile_ids'] ?? [] as $profileId) {
                $profileId = trim((string) $profileId);
                if ($profileId !== '') {
                    $ids[$profileId] = true;
                }
            }
        }

        $contactSourceId = trim((string) ($profile->contact_source_account_profile_id ?? ''));
        if ($contactSourceId !== '') {
            $ids[$contactSourceId] = true;
        }

        return array_keys($ids);
    }
}
