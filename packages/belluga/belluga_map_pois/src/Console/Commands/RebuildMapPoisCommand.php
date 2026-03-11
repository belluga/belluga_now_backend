<?php

declare(strict_types=1);

namespace Belluga\MapPois\Console\Commands;

use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Contracts\MapPoiSettingsContract;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Console\Command;

class RebuildMapPoisCommand extends Command
{
    protected $signature = 'map-pois:rebuild
        {source=all : all|events|account_profiles|static_assets}
        {--batch-size= : Override rebuild batch size}
        {--no-purge : Keep existing projections and only upsert}';

    protected $description = 'Rebuild map_pois projections from source aggregates.';

    public function handle(
        MapPoiProjectionService $projectionService,
        MapPoiSourceReaderContract $sourceReader,
        MapPoiSettingsContract $settings,
    ): int {
        $source = strtolower((string) $this->argument('source'));
        $allowedSources = ['all', 'events', 'account_profiles', 'static_assets'];
        if (! in_array($source, $allowedSources, true)) {
            $this->error('Invalid source. Use one of: all, events, account_profiles, static_assets.');

            return self::INVALID;
        }

        $ingest = $settings->resolveMapIngestSettings();
        $enabled = (bool) ($ingest['rebuild']['enabled'] ?? true);
        if (! $enabled) {
            $this->error('Map rebuild is disabled by tenant settings (map_ingest.rebuild.enabled=false).');

            return self::FAILURE;
        }

        $batchSize = $this->resolveBatchSize($ingest);
        $refTypes = $this->resolveRefTypes($source);

        if (! $this->option('no-purge')) {
            foreach ($refTypes as $refType) {
                MapPoi::query()->where('ref_type', $refType)->delete();
            }
        }

        $processed = 0;
        $upserted = 0;

        if (in_array('event', $refTypes, true)) {
            [$processed, $upserted] = $this->rebuildEvents(
                $processed,
                $upserted,
                $batchSize,
                $sourceReader,
                $projectionService,
            );
        }

        if (in_array('account_profile', $refTypes, true)) {
            [$processed, $upserted] = $this->rebuildAccountProfiles(
                $processed,
                $upserted,
                $batchSize,
                $sourceReader,
                $projectionService,
            );
        }

        if (in_array('static', $refTypes, true)) {
            [$processed, $upserted] = $this->rebuildStaticAssets(
                $processed,
                $upserted,
                $batchSize,
                $sourceReader,
                $projectionService,
            );
        }

        $this->info(sprintf('Map rebuild completed. processed=%d upserted=%d', $processed, $upserted));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $ingest
     */
    private function resolveBatchSize(array $ingest): int
    {
        $optionValue = $this->option('batch-size');
        $resolved = is_numeric($optionValue)
            ? (int) $optionValue
            : (int) ($ingest['rebuild']['batch_size'] ?? 200);

        if ($resolved < 1) {
            $resolved = 1;
        }

        return min($resolved, 5000);
    }

    /**
     * @return array<int, string>
     */
    private function resolveRefTypes(string $source): array
    {
        return match ($source) {
            'events' => ['event'],
            'account_profiles' => ['account_profile'],
            'static_assets' => ['static'],
            default => ['event', 'account_profile', 'static'],
        };
    }

    /**
     * @return array{int, int}
     */
    private function rebuildEvents(
        int $processed,
        int $upserted,
        int $batchSize,
        MapPoiSourceReaderContract $sourceReader,
        MapPoiProjectionService $projectionService,
    ): array {
        $this->line('Rebuilding events...');

        foreach ($sourceReader->allEventIds() as $eventId) {
            $processed++;

            $event = $sourceReader->findEventById($eventId);
            if (! $event) {
                continue;
            }

            $projectionService->upsertFromEvent($event);
            $upserted++;

            if ($processed % $batchSize === 0) {
                $this->line(sprintf('Progress: processed=%d upserted=%d', $processed, $upserted));
            }
        }

        return [$processed, $upserted];
    }

    /**
     * @return array{int, int}
     */
    private function rebuildAccountProfiles(
        int $processed,
        int $upserted,
        int $batchSize,
        MapPoiSourceReaderContract $sourceReader,
        MapPoiProjectionService $projectionService,
    ): array {
        $this->line('Rebuilding account profiles...');

        foreach ($sourceReader->allAccountProfileIds() as $profileId) {
            $processed++;

            $profile = $sourceReader->findAccountProfileById($profileId);
            if (! $profile) {
                continue;
            }

            $projectionService->upsertFromAccountProfile($profile);
            $upserted++;

            if ($processed % $batchSize === 0) {
                $this->line(sprintf('Progress: processed=%d upserted=%d', $processed, $upserted));
            }
        }

        return [$processed, $upserted];
    }

    /**
     * @return array{int, int}
     */
    private function rebuildStaticAssets(
        int $processed,
        int $upserted,
        int $batchSize,
        MapPoiSourceReaderContract $sourceReader,
        MapPoiProjectionService $projectionService,
    ): array {
        $this->line('Rebuilding static assets...');

        foreach ($sourceReader->allStaticAssetIds() as $assetId) {
            $processed++;

            $asset = $sourceReader->findStaticAssetById($assetId);
            if (! $asset) {
                continue;
            }

            $projectionService->upsertFromStaticAsset($asset);
            $upserted++;

            if ($processed % $batchSize === 0) {
                $this->line(sprintf('Progress: processed=%d upserted=%d', $processed, $upserted));
            }
        }

        return [$processed, $upserted];
    }
}
