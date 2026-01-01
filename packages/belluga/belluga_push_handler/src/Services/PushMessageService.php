<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;

class PushMessageService
{
    public function __construct(
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushPlanPolicyContract $planPolicy
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * @param array<string, mixed> $payload
     */
    public function create(string $scope, ?string $accountId, array $payload): PushMessage
    {
        $payload['scope'] = $scope;
        if ($scope === 'account') {
            $payload['partner_id'] = $accountId;
        }
        $payload['status'] = $payload['status'] ?? 'scheduled';
        $payload['active'] = $payload['active'] ?? true;
        $payload['delivery'] = $this->withTtlMinutes($payload['delivery'] ?? []);
        $payload['metrics'] = $payload['metrics'] ?? [
            'sent_count' => 0,
            'opened_count' => 0,
            'clicked_count' => 0,
            'dismissed_count' => 0,
            'unique_opened_count' => 0,
            'unique_clicked_count' => 0,
            'unique_dismissed_count' => 0,
            'step_view_counts' => [],
            'button_click_counts' => [],
            'accepted_count' => 0,
            'delivered_count' => 0,
        ];

        $message = PushMessage::create($payload);

        $this->dispatchSend($message, $scope, $accountId);

        return $message;
    }

    /**
     * @param array<string, mixed> $delivery
     * @return array<string, mixed>
     */
    private function withTtlMinutes(array $delivery): array
    {
        if (isset($delivery['expires_at'])) {
            $expiresAt = Carbon::parse($delivery['expires_at']);
            $delivery['ttl_minutes'] = max(0, now()->diffInMinutes($expiresAt, false));
        }

        return $delivery;
    }

    public function dispatchSend(PushMessage $message, string $scope, ?string $accountId): void
    {
        $scheduledAt = data_get($message->delivery, 'scheduled_at');
        $audienceSize = $this->audienceService->audienceSize($message);

        if ($scope === 'account' && $accountId !== null) {
            if (! $this->planPolicy->canSend($accountId, $message, $audienceSize)) {
                return;
            }
        }

        $job = new SendPushMessageJob((string) $message->_id, $scope, $accountId);

        if ($scheduledAt) {
            Bus::dispatch($job->delay(Carbon::parse($scheduledAt)));
            return;
        }

        Bus::dispatch($job);
    }
}
