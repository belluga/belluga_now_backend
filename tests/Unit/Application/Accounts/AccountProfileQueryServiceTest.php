<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileContactChannelsService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfilePublicCatalogSnapshotReader;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Accounts\AccountPublicationStateService;
use App\Application\RuntimeDiscoveryFilterCatalogService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Support\RichText\RichTextReadCanonicalizer;
use MongoDB\BSON\ObjectId;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AccountProfileQueryServiceTest extends TestCase
{
    public function test_near_aggregation_id_resolution_accepts__id_and_id_variants(): void
    {
        $contactChannelsService = (new ReflectionClass(
            AccountProfileContactChannelsService::class
        ))->newInstanceWithoutConstructor();
        $runtimeCatalogService = $this->app->make(RuntimeDiscoveryFilterCatalogService::class);

        $service = new AccountProfileQueryService(
            $this->createMock(AccountOwnershipStateService::class),
            new AccountPublicationStateService,
            $this->createMock(AccountProfileMediaService::class),
            $this->createMock(TaxonomyTermSummaryResolverService::class),
            new AccountProfileTypeSetProvider,
            new AccountProfilePublicCatalogSnapshotReader(new AccountProfileTypeCapabilityCatalog),
            $contactChannelsService,
            $runtimeCatalogService,
            new RichTextReadCanonicalizer,
        );

        $resolver = new ReflectionMethod($service, 'resolveAggregateRowId');
        $resolver->setAccessible(true);

        $objectId = new ObjectId;
        $objectIdHex = (string) $objectId;

        $this->assertSame(
            $objectIdHex,
            $resolver->invoke($service, ['_id' => $objectId]),
        );
        $this->assertSame(
            $objectIdHex,
            $resolver->invoke($service, ['id' => $objectIdHex]),
        );
        $this->assertSame(
            $objectIdHex,
            $resolver->invoke($service, ['id' => ['$oid' => $objectIdHex]]),
        );
        $this->assertNull($resolver->invoke($service, ['distance_meters' => 150.0]));
    }
}
