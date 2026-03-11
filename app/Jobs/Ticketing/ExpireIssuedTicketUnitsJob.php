<?php

declare(strict_types=1);

namespace App\Jobs\Ticketing;

use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Ticketing\Application\Lifecycle\TicketUnitLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExpireIssuedTicketUnitsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $batchSize = 500,
    ) {}

    public function handle(
        TicketUnitLifecycleService $lifecycle,
        SettingsStoreContract $settingsStore,
    ): void {
        $graceMinutes = $this->resolveGraceMinutes($settingsStore);
        $threshold = Carbon::now()->subMinutes($graceMinutes);

        $occurrences = EventOccurrence::query()
            ->whereNull('deleted_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $threshold)
            ->orderBy('ends_at')
            ->limit($this->batchSize)
            ->get();

        $expiredCount = 0;
        foreach ($occurrences as $occurrence) {
            $expiredCount += $lifecycle->expireIssuedByOccurrence(
                occurrenceId: (string) $occurrence->getAttribute('_id'),
                occurrenceEndAt: Carbon::instance($occurrence->ends_at),
                graceMinutes: $graceMinutes,
            );
        }

        if ($expiredCount > 0) {
            Log::info('ticketing_lapse_processed', [
                'expired_units' => $expiredCount,
                'occurrences_checked' => $occurrences->count(),
                'grace_minutes' => $graceMinutes,
            ]);
        }
    }

    private function resolveGraceMinutes(SettingsStoreContract $settingsStore): int
    {
        $values = $settingsStore->getNamespaceValue('tenant', 'ticketing_lifecycle');
        $raw = $values['issued_expiry_grace_minutes'] ?? 30;

        if (! is_numeric($raw)) {
            return 30;
        }

        return max(0, min(1440, (int) $raw));
    }
}
