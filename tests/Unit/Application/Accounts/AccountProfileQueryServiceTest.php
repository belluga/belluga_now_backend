<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\Accounts\AccountOwnershipStateService;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use MongoDB\BSON\ObjectId;
use ReflectionMethod;
use Tests\TestCase;

class AccountProfileQueryServiceTest extends TestCase
{
    public function test_near_aggregation_id_resolution_accepts__id_and_id_variants(): void
    {
        $service = new AccountProfileQueryService(
            $this->createMock(AccountOwnershipStateService::class),
            $this->createMock(AccountProfileMediaService::class),
            $this->createMock(TaxonomyTermSummaryResolverService::class),
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
