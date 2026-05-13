<?php

declare(strict_types=1);

namespace App\Jobs\Push;

use App\Application\Push\PushTopicMembershipService;
use Belluga\PushHandler\Contracts\PushTenantContextContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcilePushTokenTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $tenantSlug,
        private readonly string $userId,
        private readonly string $pushToken,
    ) {
        $this->afterCommit();
    }

    public function handle(
        PushTenantContextContract $tenantContext,
        PushTopicMembershipService $memberships,
    ): void {
        if ($this->tenantSlug === '' || $this->userId === '' || $this->pushToken === '') {
            return;
        }

        $tenantContext->runForTenantSlug($this->tenantSlug, function () use ($memberships): void {
            $memberships->syncTokenForUser($this->userId, $this->pushToken);
        });
    }
}
