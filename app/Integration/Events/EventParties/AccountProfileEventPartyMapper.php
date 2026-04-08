<?php

declare(strict_types=1);

namespace App\Integration\Events\EventParties;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use Belluga\Events\Contracts\EventPartyMapperContract;

class AccountProfileEventPartyMapper implements EventPartyMapperContract
{
    public function __construct(
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
    ) {}

    public function partyType(): string
    {
        return '*';
    }

    public function defaultCanEdit(): bool
    {
        return true;
    }

    public function mapMetadata(array $source): array
    {
        return [
            'display_name' => isset($source['display_name']) ? (string) $source['display_name'] : null,
            'slug' => isset($source['slug']) ? (string) $source['slug'] : null,
            'profile_type' => isset($source['profile_type']) ? (string) $source['profile_type'] : null,
            'avatar_url' => $source['avatar_url'] ?? null,
            'cover_url' => $source['cover_url'] ?? null,
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->resolve(
                is_array($source['taxonomy_terms'] ?? null) ? $source['taxonomy_terms'] : []
            ),
        ];
    }
}
