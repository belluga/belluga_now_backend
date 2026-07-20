<?php

declare(strict_types=1);

namespace Tests\Unit\Guardrails;

use Tests\TestCase;

class AccountProfilePublicCatalogSnapshotGuardrailTest extends TestCase
{
    public function test_public_query_service_uses_the_snapshot_reader_and_policy_not_public_type_provider_methods(): void
    {
        $source = $this->readSource('app/Application/AccountProfiles/AccountProfileQueryService.php');

        $this->assertStringContainsString('AccountProfilePublicCatalogSnapshotReader', $source);
        $this->assertStringContainsString('AccountProfilePublicCatalogEligibilityPolicy', $source);
        $this->assertStringNotContainsString('->publicCatalogTypes()', $source);
        $this->assertStringNotContainsString('->publicPoiCatalogTypes()', $source);
        $this->assertStringNotContainsString('->isPublicCatalog(', $source);
    }

    public function test_public_formatter_and_nested_groups_use_the_shared_eligibility_policy(): void
    {
        $formatter = $this->readSource('app/Application/AccountProfiles/AccountProfileFormatterService.php');
        $nestedGroups = $this->readSource('app/Application/AccountProfiles/AccountProfileNestedGroupService.php');

        $this->assertStringContainsString('AccountProfilePublicCatalogSnapshotReader', $formatter);
        $this->assertStringContainsString('canOpenPublicDetail($profile)', $formatter);
        $this->assertStringNotContainsString('->isPublicCatalog(', $formatter);
        $this->assertStringContainsString('AccountProfilePublicCatalogEligibilityPolicy $publicCatalogPolicy', $nestedGroups);
        $this->assertStringContainsString('applyCatalogConstraint(', $nestedGroups);
        $this->assertStringContainsString('isPublicNestedParent($parentProfile)', $nestedGroups);
        $this->assertStringNotContainsString('->publicCatalogTypes()', $nestedGroups);
    }

    public function test_discovery_filter_consumers_resolve_the_request_scoped_snapshot_without_direct_type_queries(): void
    {
        $provider = $this->readSource(
            'app/Integration/DiscoveryFilters/AccountProfileDiscoveryFilterEntityProvider.php',
        );
        $catalog = $this->readSource(
            'app/Application/DiscoveryFilters/DiscoveryFilterPublicCatalogService.php',
        );

        $this->assertStringContainsString('AccountProfilePublicCatalogSnapshotReader', $provider);
        $this->assertStringContainsString('catalogSnapshot()->filterOptions()', $provider);
        $this->assertStringNotContainsString('TenantProfileType::query()', $provider);
        $this->assertStringContainsString('AccountProfilePublicCatalogSnapshotReader', $catalog);
        $this->assertStringContainsString('catalogSnapshot()->filterOptions()', $catalog);
        $this->assertStringNotContainsString('TenantProfileType::query()', $catalog);
        $this->assertStringNotContainsString('->publicDiscoverySurface()', $catalog);
    }

    public function test_public_event_reads_do_not_repair_account_profiles_with_live_projections(): void
    {
        $adapter = $this->readSource('app/Integration/Events/AccountProfileResolverAdapter.php');
        $queryService = $this->readSource(
            'packages/belluga/belluga_events/src/Application/Events/EventQueryService.php',
        );
        $contract = $this->readSource(
            'packages/belluga/belluga_events/src/Contracts/EventProfileResolverContract.php',
        );

        // Event snapshots are built on writes; public Event reads never repair them.
        $this->assertStringNotContainsString('resolvePublicAccountProfileProjectionsByIds', $adapter);
        $this->assertStringNotContainsString('resolvePublicAccountProfileProjectionsByIds', $queryService);
        $this->assertStringNotContainsString('publicAccountProfileProjection(', $queryService);
        $this->assertStringNotContainsString('prefetchPublicAccountProfileProjections', $queryService);
        $this->assertStringNotContainsString('resolvePublicAccountProfileProjectionsByIds', $contract);
    }

    private function readSource(string $relativePath): string
    {
        $path = base_path($relativePath);
        $source = file_get_contents($path);

        $this->assertIsString($source);

        return $source;
    }
}
