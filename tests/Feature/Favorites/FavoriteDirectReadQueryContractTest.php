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
        $this->assertStringContainsString('liveAndNextOccurrencesForMemberProfiles($normalizedProfileIds, $now)', $source);
        $this->assertStringContainsString('lastOccurrencesForMemberProfiles($normalizedProfileIds, $now)', $source);
        $this->assertStringNotContainsString('event_parties', $source);
        $this->assertStringNotContainsString("getAttribute('artists')", $source);
        $this->assertStringNotContainsString("getAttribute('linked_account_profiles')", $source);
    }

    public function test_member_pipeline_preserves_scoped_index_joins_and_complete_bounded_winners(): void
    {
        $tenant = $this->createMock(\Belluga\Events\Contracts\EventTenantContextContract::class);
        $tenant->method('resolveCurrentTenantId')->willReturn('tenant-query-contract');
        $resolver = $this->createMock(\Belluga\Events\Contracts\EventProfileResolverContract::class);
        $store = new \Belluga\Events\Application\Events\EventOccurrenceNestedAccountStore($tenant, $resolver);
        $method = new \ReflectionMethod($store, 'memberOccurrenceStatePipeline');
        foreach ([false, true] as $pastOnly) {
            $pipeline = $method->invoke($store, ['profile-a', 'profile-b'], \Illuminate\Support\Carbon::parse('2026-03-20T12:00:00Z'), $pastOnly);
            $this->assertSame([
                'tenant_id' => 'tenant-query-contract',
                'parent_type' => 'event_occurrence',
                'doc_type' => 'member_row',
                'nested_profile.id' => ['$in' => ['profile-a', 'profile-b']],
                'parent_id' => ['$type' => 'string', '$ne' => ''],
            ], $pipeline[0]['$match']);
            $lookups = array_values(array_filter($pipeline, static fn (array $stage): bool => isset($stage['$lookup'])));
            $this->assertCount(2, $lookups);
            $this->assertSame('accounts_nested', $lookups[0]['$lookup']['from']);
            $this->assertSame('tenant-query-contract', $lookups[0]['$lookup']['pipeline'][0]['$match']['tenant_id']);
            $this->assertSame('_id', $lookups[1]['$lookup']['foreignField']);
            $this->assertSame('occurrence_keys', $lookups[1]['$lookup']['localField']);
            $this->assertSame(['$first' => '$occurrence'], $pipeline[array_key_last($pipeline)]['$group']['occurrence']);
            $serialized = json_encode($pipeline, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('$push', $serialized);
            $this->assertStringNotContainsString('$addToSet', $serialized);
            $this->assertStringNotContainsString('event_parties', $serialized);
        }
        $this->assertSame([], $store->liveAndNextOccurrencesForMemberProfiles([], now()));
        $this->assertSame([], $store->lastOccurrencesForMemberProfiles([], now()));
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
