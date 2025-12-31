<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Jobs;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $messageId,
        private readonly string $scope,
        private readonly ?string $accountId
    ) {
    }

    public function handle(
        PushDeliveryService $deliveryService,
        PushRecipientResolver $recipientResolver
    ): void
    {
        $message = PushMessage::query()->find($this->messageId);
        if (! $message || ! $message->isActive() || $message->isExpired()) {
            return;
        }

        $recipients = $recipientResolver->resolveTokens($message, $this->scope, $this->accountId);
        if ($this->scope === 'account' && $this->accountId !== null) {
            $audienceSize = count($recipients);
            if (! app(\Belluga\PushHandler\Contracts\PushPlanPolicyContract::class)->canSend($this->accountId, $message, $audienceSize)) {
                return;
            }
        }

        if ($recipients === []) {
            return;
        }

        $response = $deliveryService->deliver($message, $recipients);

        $metrics = $message->metrics ?? [];
        $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + (int) ($response['accepted_count'] ?? 0);
        $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;

        $message->metrics = $metrics;
        $message->status = 'sent';
        $message->sent_at = Carbon::now();
        $message->save();
    }
}
