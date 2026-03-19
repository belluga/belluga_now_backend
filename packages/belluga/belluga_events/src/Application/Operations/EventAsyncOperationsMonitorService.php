<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Operations;

use Belluga\Events\Contracts\EventAsyncQueueMetricsProviderContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EventAsyncOperationsMonitorService
{
    public const int SLO_P95_TARGET_SECONDS = 15;

    public const int SLO_P99_TARGET_SECONDS = 60;

    public const int STALENESS_MAX_AGE_SECONDS = 60;

    public const int STALENESS_BREACH_MINUTES = 5;

    public const string CACHE_BREACH_COUNT_KEY = 'events:async:staleness:breach_count';

    public const string CACHE_ALERT_ACTIVE_KEY = 'events:async:staleness:alert_active';

    public function __construct(
        private readonly EventAsyncQueueMetricsProviderContract $metricsProvider
    ) {}

    public function evaluate(): void
    {
        $snapshot = $this->metricsProvider->snapshot();
        if ($snapshot->isUnavailable()) {
            Log::warning('events_async_queue_metrics_unavailable', [
                'status' => $snapshot->status(),
                'reason' => $snapshot->reason(),
            ]);

            return;
        }

        $ages = $snapshot->pendingAgesInSeconds();
        if ($snapshot->isEmpty()) {
            $this->clearStalenessState(null);

            return;
        }

        sort($ages);
        $maxAge = (int) max($ages);
        $p95 = $this->percentile($ages, 95);
        $p99 = $this->percentile($ages, 99);

        Log::info('events_async_side_effects_lag_snapshot', [
            'pending_jobs' => count($ages),
            'p95_seconds' => $p95,
            'p99_seconds' => $p99,
            'max_age_seconds' => $maxAge,
            'slo_targets' => [
                'p95_seconds' => self::SLO_P95_TARGET_SECONDS,
                'p99_seconds' => self::SLO_P99_TARGET_SECONDS,
            ],
        ]);

        if ($p95 > self::SLO_P95_TARGET_SECONDS || $p99 > self::SLO_P99_TARGET_SECONDS) {
            Log::warning('events_async_side_effects_slo_breach', [
                'pending_jobs' => count($ages),
                'p95_seconds' => $p95,
                'p99_seconds' => $p99,
                'max_age_seconds' => $maxAge,
            ]);
        }

        if ($maxAge > self::STALENESS_MAX_AGE_SECONDS) {
            $this->registerStalenessBreach($maxAge);

            return;
        }

        $this->clearStalenessState($maxAge);
    }

    /**
     * @param  array<int, int>  $ages
     */
    private function percentile(array $ages, int $percent): int
    {
        $count = count($ages);
        if ($count === 0) {
            return 0;
        }

        $index = (int) ceil(($percent / 100) * $count) - 1;
        $index = max(0, min($count - 1, $index));

        return (int) $ages[$index];
    }

    private function registerStalenessBreach(int $maxAge): void
    {
        $breachCount = ((int) Cache::get(self::CACHE_BREACH_COUNT_KEY, 0)) + 1;
        Cache::put(self::CACHE_BREACH_COUNT_KEY, $breachCount, now()->addHours(6));

        if ($breachCount < self::STALENESS_BREACH_MINUTES) {
            return;
        }

        if ((bool) Cache::get(self::CACHE_ALERT_ACTIVE_KEY, false)) {
            return;
        }

        Cache::forever(self::CACHE_ALERT_ACTIVE_KEY, true);

        Log::critical('events_async_queue_staleness_alert', [
            'max_age_seconds' => $maxAge,
            'threshold_seconds' => self::STALENESS_MAX_AGE_SECONDS,
            'breach_minutes' => $breachCount,
            'required_breach_minutes' => self::STALENESS_BREACH_MINUTES,
        ]);
    }

    private function clearStalenessState(?int $maxAge): void
    {
        $wasAlertActive = (bool) Cache::get(self::CACHE_ALERT_ACTIVE_KEY, false);

        Cache::forget(self::CACHE_BREACH_COUNT_KEY);
        Cache::forget(self::CACHE_ALERT_ACTIVE_KEY);

        if ($wasAlertActive) {
            Log::info('events_async_queue_staleness_recovered', [
                'max_age_seconds' => $maxAge ?? 0,
                'threshold_seconds' => self::STALENESS_MAX_AGE_SECONDS,
            ]);
        }
    }
}
