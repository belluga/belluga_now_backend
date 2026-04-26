<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use Belluga\Events\Application\Events\EventPublicationManagementService;
use Belluga\Events\Application\Events\ScheduledEventPublicationSelectionService;
use Belluga\Events\Jobs\PublishScheduledEventsJob;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class PublishScheduledEventsJobDelegationTest extends TestCase
{
    public function test_job_delegates_selection_and_mutation_to_canonical_services(): void
    {
        $now = Carbon::parse('2026-04-23T12:34:00Z');
        Carbon::setTestNow($now);

        $selectionService = Mockery::mock(ScheduledEventPublicationSelectionService::class);
        $managementService = Mockery::mock(EventPublicationManagementService::class);

        $selectionService->shouldReceive('dueEventIds')
            ->once()
            ->with(Mockery::on(static fn (Carbon $candidate): bool => $candidate->equalTo($now)))
            ->andReturn(['evt-1', 'evt-2']);

        $managementService->shouldReceive('publishScheduledEventIfDue')
            ->once()
            ->with('evt-1', Mockery::on(static fn (Carbon $candidate): bool => $candidate->equalTo($now)))
            ->andReturn(['published' => true]);
        $managementService->shouldReceive('publishScheduledEventIfDue')
            ->once()
            ->with('evt-2', Mockery::on(static fn (Carbon $candidate): bool => $candidate->equalTo($now)))
            ->andReturn(['published' => false]);

        try {
            (new PublishScheduledEventsJob)->handle($selectionService, $managementService);
        } finally {
            Carbon::setTestNow();
        }
    }
}
