<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Contracts\PushChannelAuthorizationContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;

class PushMessageService
{
    public function __construct(
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushAudienceCanonicalizer $audienceCanonicalizer,
        private readonly PushPlanPolicyContract $planPolicy,
        private readonly PushAudienceTopologyClassifier $audienceTopology,
        private readonly PushChannelAuthorizationContract $channelAuthorization,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(string $scope, ?string $accountId, array $payload): PushMessage
    {
        $payload['scope'] = $scope;
        if ($scope === 'account') {
            $payload['partner_id'] = $accountId;
        }
        $payload['status'] = $payload['status'] ?? 'scheduled';
        $payload['active'] = $payload['active'] ?? true;
        $payload['delivery'] = $payload['delivery'] ?? [];
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
            'delivery_topology_counts' => [
                PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT => 0,
                PushAudienceTopologyClassifier::CHANNEL_TOPIC => 0,
            ],
        ];

        $payload = $this->preparePayload($scope, $accountId, $payload);

        $message = PushMessage::create($payload);

        $this->dispatchSend($message, $scope, $accountId);

        return $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(PushMessage $message, string $scope, ?string $accountId, array $payload): PushMessage
    {
        if ($scope === 'account') {
            $payload['partner_id'] = $accountId;
        }

        $payload = $this->preparePayload($scope, $accountId, $payload, $message);

        $message->fill($payload);
        $message->save();

        return $message;
    }

    public function dispatchSend(PushMessage $message, string $scope, ?string $accountId): void
    {
        $this->channelAuthorization->assertCanDispatch($scope, $accountId, $message);
        $this->audienceTopology->assertDispatchable($message);

        $scheduledAt = data_get($message->delivery, 'scheduled_at');
        $requestedUnits = $this->audienceService->requestedUnits($message);

        if ($scope === 'account' && $accountId !== null) {
            if (! $this->planPolicy->canSend($accountId, $message, $requestedUnits)) {
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preparePayload(string $scope, ?string $accountId, array $payload, ?PushMessage $existing = null): array
    {
        $shouldNormalizeAudience = array_key_exists('audience', $payload) || $existing === null;
        if ($shouldNormalizeAudience) {
            $currentAudience = is_array($existing?->audience ?? null) ? $existing->audience : [];
            $incomingAudience = is_array($payload['audience'] ?? null) ? $payload['audience'] : $currentAudience;
            $payload['audience'] = $this->audienceCanonicalizer->canonicalize($incomingAudience);
            $this->channelAuthorization->assertCanPersist($scope, $accountId, $payload['audience']);
        }

        $candidate = $existing ? clone $existing : new PushMessage();
        $candidate->forceFill($payload);
        $this->audienceTopology->assertDispatchable($candidate);

        return $payload;
    }
}
