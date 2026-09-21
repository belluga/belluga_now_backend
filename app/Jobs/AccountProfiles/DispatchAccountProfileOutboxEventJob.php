<?php

declare(strict_types=1);

namespace App\Jobs\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

final class DispatchAccountProfileOutboxEventJob implements ShouldQueue, TenantAware
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const TIMEOUT_SECONDS = 240;

    public int $tries = 5;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(private readonly string $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }

    public function handle(AccountProfileOutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatchEvent($this->eventId);
    }
}
