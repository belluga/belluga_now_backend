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

class UnsubscribePushTokensFromAllTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  array<int, string>  $tokens
     */
    public function __construct(
        private readonly string $tenantSlug,
        private readonly array $tokens,
    ) {
        $this->afterCommit();
    }

    public function handle(
        PushTenantContextContract $tenantContext,
        PushTopicMembershipService $memberships,
    ): void {
        if ($this->tenantSlug === '' || $this->tokens === []) {
            return;
        }

        $tenantContext->runForTenantSlug($this->tenantSlug, function () use ($memberships): void {
            $memberships->unsubscribeTokensFromAll($this->tokens);
        });
    }
}
