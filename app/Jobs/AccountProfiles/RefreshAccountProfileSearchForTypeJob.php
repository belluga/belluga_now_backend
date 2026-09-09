<?php

declare(strict_types=1);

namespace App\Jobs\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Models\Tenants\AccountProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\TenantAware;

final class RefreshAccountProfileSearchForTypeJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const FAILURE_SAMPLE_LIMIT = 10;

    public function __construct(private readonly string $profileType) {}

    public function handle(AccountProfileManagementService $profiles): void
    {
        $profileType = trim($this->profileType);
        if ($profileType === '') {
            return;
        }

        $failed = 0;
        $failureSamples = [];
        foreach (AccountProfile::query()->where('profile_type', $profileType)->cursor() as $profile) {
            try {
                $profiles->update(
                    $profile,
                    [],
                    fingerprintSupplement: [
                        'source' => 'profile_type_search_refresh',
                        'profile_type' => $profileType,
                    ],
                    useAggregateRevisionCas: false,
                    forceSearchRefresh: true,
                );
            } catch (\Throwable $error) {
                $failed++;
                if (count($failureSamples) < self::FAILURE_SAMPLE_LIMIT) {
                    $failureSamples[] = [
                        'profile_id' => (string) $profile->getKey(),
                        'message' => $error->getMessage(),
                    ];
                }
            }
        }

        if ($failed > 0) {
            Log::warning('Account Profile search refresh failed for a Profile Type.', [
                'profile_type' => $profileType,
                'failed' => $failed,
                'failures' => $failureSamples,
            ]);
            throw new \RuntimeException("Account Profile search refresh failed for {$failed} profile(s).");
        }
    }
}
