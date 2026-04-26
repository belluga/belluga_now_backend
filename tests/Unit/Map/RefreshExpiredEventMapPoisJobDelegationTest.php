<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use Belluga\MapPois\Application\ExpiredEventMapPoiRefreshService;
use Belluga\MapPois\Jobs\RefreshExpiredEventMapPoisJob;
use Mockery;
use Tests\TestCase;

class RefreshExpiredEventMapPoisJobDelegationTest extends TestCase
{
    public function test_job_delegates_refresh_to_canonical_application_service(): void
    {
        $refreshService = Mockery::mock(ExpiredEventMapPoiRefreshService::class);
        $refreshService->shouldReceive('refreshExpired')->once();

        (new RefreshExpiredEventMapPoisJob)->handle($refreshService);
    }
}
