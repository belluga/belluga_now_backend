<?php

declare(strict_types=1);

namespace App\Jobs\Taxonomies;

use App\Application\Taxonomies\TaxonomySnapshotBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class RepairTaxonomyTermSnapshotsJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly ?string $taxonomyType = null,
        private readonly ?string $termValue = null,
    ) {}

    public function handle(TaxonomySnapshotBackfillService $backfillService): void
    {
        $summary = $backfillService->repair($this->taxonomyType, $this->termValue);
        $failed = (int) data_get($summary, 'totals.failed', 0);
        if ($failed > 0) {
            throw new \RuntimeException("Taxonomy term snapshot repair failed for {$failed} document(s).");
        }
    }
}
