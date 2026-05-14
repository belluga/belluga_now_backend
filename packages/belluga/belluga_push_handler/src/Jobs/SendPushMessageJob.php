<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Jobs;

use Belluga\PushHandler\Contracts\PushChannelAuthorizationContract;
use Belluga\PushHandler\Contracts\PushChannelTargetResolverContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushAudienceTopologyClassifier;
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
        PushAudienceTopologyClassifier $audienceTopology,
        PushChannelAuthorizationContract $channelAuthorization,
        PushChannelTargetResolverContract $channelTargetResolver,
    ): void {
        $message = PushMessage::query()->find($this->messageId);
        if (! $message || ! $message->isActive() || $message->isExpired()) {
            return;
        }

        try {
            $channelAuthorization->assertCanDispatch($this->scope, $this->accountId, $message);
            $audienceTopology->assertDispatchable($message);
        } catch (ValidationException) {
            return;
        }

        $topology = $audienceTopology->classify($message);
        $requestedUnits = 0;
        if ($topology === PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT) {
            try {
                $requestedUnits = $recipientResolver->countTargets(
                    $message,
                    $this->scope,
                    $this->accountId
                );
            } catch (ValidationException) {
                return;
            }
        } elseif ($topology === PushAudienceTopologyClassifier::CHANNEL_TOPIC) {
            $requestedUnits = 1;
        }

        if ($this->scope === 'account' && $this->accountId !== null) {
            if (! $pushPlanPolicy->canSend($this->accountId, $message, $requestedUnits)) {
                return;
            }
        }

        $acceptedCount = 0;
        $delivered = false;

        try {
            if ($topology === PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT) {
                if ($requestedUnits === 0) {
                    return;
                }

                $recipientResolver->streamResolvedTargetBatches(
                    $message,
                    $this->scope,
                    $this->accountId,
                    500,
                    function (array $batch) use ($deliveryService, $message, &$acceptedCount, &$delivered): void {
                        $response = $deliveryService->deliver(
                            $message,
                            $batch['tokens'],
                            $batch['token_user_map']
                        );
                        $batchAcceptedCount = (int) ($response['accepted_count'] ?? 0);
                        $acceptedCount += $batchAcceptedCount;
                        $delivered = $delivered || $batchAcceptedCount > 0;
                    }
                );
            } elseif ($topology === PushAudienceTopologyClassifier::CHANNEL_TOPIC) {
                $topic = $channelTargetResolver->resolveTopic($message);
                if (! is_string($topic) || trim($topic) === '') {
                    return;
                }

                $response = $deliveryService->deliverToTopic($message, $topic);
                $acceptedCount = (int) ($response['accepted_count'] ?? 0);
                $delivered = $acceptedCount > 0;
            }
        } catch (ValidationException) {
            return;
        }

        if (! $delivered) {
            return;
        }

        $metrics = $message->metrics ?? [];
        $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + $acceptedCount;
        $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;
        $metrics['delivery_topology_counts'] = $this->incrementTopologyMetric(
            is_array($metrics['delivery_topology_counts'] ?? null) ? $metrics['delivery_topology_counts'] : [],
            $topology
        );
        $metrics['last_delivery_topology'] = $topology;

        $message->metrics = $metrics;
        $message->status = 'sent';
        $message->sent_at = Carbon::now();
        $message->save();
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    private function incrementTopologyMetric(array $counts, string $topology): array
    {
        $normalized = [
            PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT => (int) ($counts[PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT] ?? 0),
            PushAudienceTopologyClassifier::CHANNEL_TOPIC => (int) ($counts[PushAudienceTopologyClassifier::CHANNEL_TOPIC] ?? 0),
        ];

        if (isset($normalized[$topology])) {
            $normalized[$topology]++;
        }

        return $normalized;
    }
}
