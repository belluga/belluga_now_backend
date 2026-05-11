<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Jobs;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Jobs\TenantAware;

class SendPushMessageJob implements ShouldQueue, TenantAware
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
    ) {}

    public function handle(
        PushDeliveryService $deliveryService,
        PushRecipientResolver $recipientResolver,
        PushPlanPolicyContract $pushPlanPolicy,
    ): void {
        $message = PushMessage::query()->find($this->messageId);
        if (! $message || ! $message->isActive() || $message->isExpired()) {
            return;
        }

        $audienceSize = $recipientResolver->countTargets(
            $message,
            $this->scope,
            $this->accountId
        );
        if ($this->scope === 'account' && $this->accountId !== null) {
            if (! $pushPlanPolicy->canSend($this->accountId, $message, $audienceSize)) {
                return;
            }
        }

        if ($audienceSize === 0) {
            return;
        }

        $acceptedCount = 0;
        $deliveredAnyBatch = false;

        try {
            $recipientResolver->streamResolvedTargetBatches(
                $message,
                $this->scope,
                $this->accountId,
                500,
                function (array $batch) use ($deliveryService, $message, &$acceptedCount, &$deliveredAnyBatch): void {
                    $deliveredAnyBatch = true;
                    $response = $deliveryService->deliver(
                        $message,
                        $batch['tokens'],
                        $batch['token_user_map']
                    );
                    $acceptedCount += (int) ($response['accepted_count'] ?? 0);
                }
            );
        } catch (ValidationException) {
            return;
        }

        if (! $deliveredAnyBatch) {
            return;
        }

        $metrics = $message->metrics ?? [];
        $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + $acceptedCount;
        $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;

        $message->metrics = $metrics;
        $message->status = 'sent';
        $message->sent_at = Carbon::now();
        $message->save();
    }
}
