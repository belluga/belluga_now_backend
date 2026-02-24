<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventProfileResolverContract
{
    /**
     * @return array{
     *   account_id: string,
     *   account_profile_id: string,
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }
     */
    public function resolveVenueByProfileId(string $profileId): array;

    /**
     * @param array<int, string> $artistProfileIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveArtistsByProfileIds(array $artistProfileIds): array;

    /**
     * @return array<int, string>
     */
    public function listProfileIdsForAccount(string $accountId): array;

    public function accountOwnsProfile(string $accountId, string $profileId): bool;
}
