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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function format(AccountProfile $profile, bool $includeAgendaOccurrences = false): array
    {
        $baseUrl = request()->getSchemeAndHttpHost();
        $account = Account::query()->where('_id', $profile->account_id)->first();

        $payload = [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $profile->slug,
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
            'location' => $this->formatLocation($profile->location),
            'ownership_state' => $account
                ? $this->ownershipStateService->deriveOwnershipState($account)
                : null,
            'created_at' => $profile->created_at?->toJSON(),
            'updated_at' => $profile->updated_at?->toJSON(),
            'deleted_at' => $profile->deleted_at?->toJSON(),
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
}
