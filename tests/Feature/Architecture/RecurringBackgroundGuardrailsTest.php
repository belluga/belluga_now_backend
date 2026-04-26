<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class RecurringBackgroundGuardrailsTest extends TestCase
{
    public function test_recurring_runtime_inventory_excludes_periodic_event_occurrence_reconcile(): void
    {
        $eventSummaries = collect($this->app->make(Schedule::class)->events())
            ->map(static fn (object $event): ?string => method_exists($event, 'getSummaryForDisplay')
                ? $event->getSummaryForDisplay()
                : null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('events:publication:publish_scheduled', $eventSummaries);
        $this->assertContains('events:async:monitor', $eventSummaries);
        $this->assertContains('map_pois:cleanup_orphaned', $eventSummaries);
        $this->assertContains('events:map_pois:refresh_expired', $eventSummaries);
        $this->assertContains('api-security:abuse-signals:prune', $eventSummaries);
        $this->assertNotContains(
            'events:occurrences:reconcile',
            $eventSummaries,
            'Recurring occurrence reconcile must not remain in steady-state scheduler runtime.'
        );
    }

    public function test_recurring_jobs_delegate_hidden_selection_and_mutation_rules_to_canonical_services(): void
    {
        $publishJobSource = $this->readSource('packages/belluga/belluga_events/src/Jobs/PublishScheduledEventsJob.php');
        $this->assertStringContainsString(
            'ScheduledEventPublicationSelectionService',
            $publishJobSource,
            'PublishScheduledEventsJob must delegate candidate selection to an Application service.'
        );
        $this->assertStringContainsString(
            'EventPublicationManagementService',
            $publishJobSource,
            'PublishScheduledEventsJob must delegate aggregate mutation to a canonical Application service.'
        );
        $this->assertStringNotContainsString('Event::query()', $publishJobSource);
        $this->assertStringNotContainsString('DB::connection(', $publishJobSource);
        $this->assertStringNotContainsString('->save()', $publishJobSource);
        $this->assertStringNotContainsString('mirrorPublicationByEventId(', $publishJobSource);

        $refreshExpiredSource = $this->readSource('packages/belluga/belluga_map_pois/src/Jobs/RefreshExpiredEventMapPoisJob.php');
        $this->assertStringContainsString(
            'ExpiredEventMapPoiRefreshService',
            $refreshExpiredSource,
            'RefreshExpiredEventMapPoisJob must delegate refresh selection/mutation to an Application service.'
        );
        $this->assertStringNotContainsString('MapPoi::query()', $refreshExpiredSource);
        $this->assertStringNotContainsString('findEventById(', $refreshExpiredSource);
        $this->assertStringNotContainsString('deleteByRef(', $refreshExpiredSource);
        $this->assertStringNotContainsString('upsertFromEvent(', $refreshExpiredSource);
        $this->assertStringNotContainsString("cleanup(['event'])", $refreshExpiredSource);
        $this->assertStringNotContainsString('private function refreshExpiredEventPois', $refreshExpiredSource);
    }

    public function test_manual_repair_sweeps_use_cursor_semantics_instead_of_get_all(): void
    {
        $source = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventOccurrenceReconciliationService.php');

        $this->assertStringContainsString("Event::withTrashed()\n            ->orderBy('_id')\n            ->cursor()", $source);
        $this->assertStringNotContainsString("Event::withTrashed()\n            ->get()", $source);
        $this->assertStringNotContainsString("Event::withTrashed()\n            ->get()\n            ->each", $source);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = base_path($relativePath);
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }
}
