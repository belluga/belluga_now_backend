<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;

interface EventProfileResolverContract
{
    /**
     * @return array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }
     */
    public function resolvePhysicalHostByProfileId(string $profileId): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }>
     */
    public function resolvePhysicalHostsByProfileIds(array $profileIds): array;

    /**
     * Read-model helper: return current physical-host projections for the ids
     * that still resolve as eligible hosts, keyed by profile id, without
     * throwing for missing or ineligible rows.
     *
     * @param  array<int, string>  $profileIds
     * @return array<string, array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }>
     */
    public function resolveExistingPhysicalHostsByProfileIds(array $profileIds): array;

    /**
     * Public read-model helper: return only currently publicly exposed host
     * projections, keyed by profile id, without throwing for missing or
     * ineligible rows.
     *
     * @param  array<int, string>  $profileIds
     * @return array<string, array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }>
     */
    public function resolveExistingPublicPhysicalHostsByProfileIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveEventPartyProfilesByIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    public function resolveExistingEventPartyProfilesByIds(array $profileIds): array;

    /**
     * Public read-model helper: return only currently publicly exposed related
     * profiles, keyed by profile id, without throwing for missing or ineligible
     * rows.
     *
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    public function resolveExistingPublicEventPartyProfilesByIds(array $profileIds): array;

    /**
     * Public member-tab helper: return the current card projection for every
     * still-existing selected related profile, keyed by profile id, without
     * reapplying catalog/discovery admission rules. Navigation remains governed
     * only by `can_open_public_detail` / `public_detail_path`.
     *
     * @param  array<int, string>  $profileIds
     * @return array<string, array<string, mixed>>
     */
    public function resolveExistingEventPartyDisplayProfilesByIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array{
     *   id: string,
     *   label: ?string,
     *   search_key: ?string,
     *   profile_type: ?string,
     *   category: ?string,
     *   taxonomy_terms_flat: array<int, string>,
     *   slug: ?string,
     *   avatar_url: ?string,
     *   cover_url: ?string
     * }>
     */
    public function resolveNestedAccountProfileSnapshotsByIds(array $profileIds): array;

    /**
     * @return array<int, string>
     */
    public function listProfileIdsForAccount(string $accountId): array;

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    public function resolveAccountIdsForProfileIds(array $profileIds): array;

    /**
     * @param  array<int, string>  $types
     * @return array<string, string>
     */
    public function resolveProfileTypePluralLabelsByTypes(array $types): array;

    public function accountOwnsProfile(string $accountId, string $profileId): bool;

    public function paginateAccountProfileCandidates(
        string $candidateType,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15,
        ?string $accountId = null,
        ?string $baseUrl = null,
        ?string $profileType = null,
    ): LengthAwarePaginator;

    public function isProfileTypeQueryable(string $profileType): bool;

    public function isProfileTypePubliclyNavigable(string $profileType): bool;
}
