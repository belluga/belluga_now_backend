<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use Tests\TestCase;

class FavoriteDirectReadQueryContractTest extends TestCase
{
    public function test_account_profile_favorite_direct_read_uses_canonical_public_agenda_association_fields(): void
    {
        $source = $this->readSource('app/Integration/Favorites/AccountProfileFavoriteDirectReadService.php');

        $this->assertStringContainsString("where('place_ref.type', 'account_profile')", $source);
        $this->assertStringContainsString("'party_ref_id' => ['\$in' => \$profileIdCandidates]", $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
    }

    public function test_favorites_integration_provider_no_longer_dispatches_snapshot_rebuilds_on_write_path(): void
    {
        $source = $this->readSource('app/Providers/PackageIntegration/FavoritesIntegrationServiceProvider.php');

        $this->assertStringNotContainsString('dispatchSync(', $source);
        $this->assertStringNotContainsString('RebuildFavoriteSnapshotJob', $source);
    }

    public function test_favorites_registry_config_no_longer_declares_account_profile_snapshot_runtime_fields(): void
    {
        $source = $this->readSource('config/favorites.php');

        $this->assertStringNotContainsString("'snapshot_builder'", $source);
        $this->assertStringNotContainsString("'snapshot_collection'", $source);
        $this->assertStringNotContainsString("'requires_specific_indexes'", $source);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }
}
