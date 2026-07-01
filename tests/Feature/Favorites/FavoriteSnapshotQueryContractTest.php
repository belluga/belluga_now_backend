<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use Tests\TestCase;

class FavoriteSnapshotQueryContractTest extends TestCase
{
    public function test_account_profile_favorite_snapshot_builder_uses_canonical_public_agenda_association_fields(): void
    {
        $source = $this->readSource('app/Integration/Favorites/AccountProfileFavoriteSnapshotBuilder.php');

        $this->assertStringContainsString("where('place_ref.type', 'account_profile')", $source);
        $this->assertStringContainsString("'party_ref_id' => ['\$in' => \$profileIdCandidates]", $source);
        $this->assertStringNotContainsString("orWhere('artists.id'", $source);
        $this->assertStringNotContainsString("orWhere('linked_account_profiles.id'", $source);
    }

    public function test_favorites_snapshot_rebuild_hook_uses_canonical_occurrence_relations_only(): void
    {
        $source = $this->readSource('app/Providers/PackageIntegration/FavoritesIntegrationServiceProvider.php');

        $this->assertStringContainsString("getAttribute('place_ref')", $source);
        $this->assertStringContainsString("getAttribute('event_parties')", $source);
        $this->assertStringContainsString("party_ref_id", $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }
}
