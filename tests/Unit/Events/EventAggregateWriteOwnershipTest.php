<?php

declare(strict_types=1);

namespace Tests\Unit\Events;

use PHPUnit\Framework\TestCase;

class EventAggregateWriteOwnershipTest extends TestCase
{
    public function test_event_aggregate_consistency_routes_all_mutations_through_shared_transaction_owned_services(): void
    {
        $managementSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventManagementService.php');
        $publicationSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventPublicationManagementService.php');
        $reconciliationSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventOccurrenceReconciliationService.php');
        $aggregateSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventAggregateWriteService.php');
        $snapshotSource = $this->readSource('packages/belluga/belluga_events/src/Application/Events/EventOccurrencePayloadSnapshotService.php');
        $transactionSource = $this->readSource('packages/belluga/belluga_events/src/Application/Transactions/EventTransactionRunner.php');

        $this->assertStringContainsString('EventAggregateWriteService', $managementSource);
        $this->assertStringNotContainsString('private function runTenantTransaction', $managementSource);
        $this->assertStringNotContainsString("DB::connection('tenant')", $managementSource);

        $this->assertStringContainsString('EventAggregateWriteService', $publicationSource);
        $this->assertStringNotContainsString('private function runTenantTransaction', $publicationSource);
        $this->assertStringNotContainsString("DB::connection('tenant')", $publicationSource);

        $this->assertStringContainsString('EventAggregateWriteService', $reconciliationSource);
        $this->assertStringNotContainsString('EventOccurrenceSyncService', $reconciliationSource);
        $this->assertStringNotContainsString('resolveOccurrences(', $reconciliationSource);
        $this->assertStringNotContainsString('private function runTenantTransaction', $reconciliationSource);

        $this->assertStringContainsString('EventTransactionRunner', $aggregateSource);
        $this->assertStringContainsString('EventOccurrencePayloadSnapshotService', $aggregateSource);
        $this->assertStringContainsString('syncFromEvent', $aggregateSource);
        $this->assertStringContainsString('mirrorPublicationByEventId', $aggregateSource);

        $this->assertStringContainsString('own_event_parties', $snapshotSource);
        $this->assertStringContainsString('programming_items', $snapshotSource);
        $this->assertStringContainsString('date_time_start', $snapshotSource);

        $this->assertStringContainsString("DB::connection('tenant')", $transactionSource);
        $this->assertStringContainsString('transaction support is required for events writes', $transactionSource);
    }

    private function readSource(string $relativePath): string
    {
        $fullPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$relativePath;
        $contents = file_get_contents($fullPath);
        $this->assertNotFalse($contents, sprintf('Failed to read [%s].', $fullPath));

        return (string) $contents;
    }
}
