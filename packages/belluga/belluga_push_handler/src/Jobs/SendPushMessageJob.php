<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Jobs;

use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $messageId,
        private readonly string $accountId
    ) {
    }

    public function handle(FcmClientContract $fcmClient, PushMessageAudienceService $audienceService): void
    {
        $message = PushMessage::query()->find($this->messageId);
        if (! $message || ! $message->isActive() || $message->isExpired()) {
            return;
        }

        $audienceSize = $audienceService->audienceSize($message);
        $response = $fcmClient->send($message, $audienceSize);

        $metrics = $message->metrics ?? [];
        $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + (int) ($response['accepted_count'] ?? 0);
        $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;

        $message->metrics = $metrics;
        $message->status = 'sent';
        $message->sent_at = Carbon::now();
        $message->save();
    }
}
