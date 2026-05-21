<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Jobs;

use Belluga\PushHandler\Contracts\PushChannelAuthorizationContract;
use Belluga\PushHandler\Contracts\PushChannelTargetResolverContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushAudienceTopologyClassifier;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushDeviceService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
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
        ?PushUserGatewayContract $users = null,
        ?PushDeviceService $pushDeviceService = null,
    ): void {
        $message = PushMessage::query()->find($this->messageId);
        if (! $message) {
            return;
        }

        if (! $message->isActive() || $message->isExpired()) {
            $this->markTerminalState(
                $message,
                'skipped',
                'inactive_or_expired'
            );

            return;
        }

        try {
            $channelAuthorization->assertCanDispatch($this->scope, $this->accountId, $message);
            $audienceTopology->assertDispatchable($message);
        } catch (ValidationException $exception) {
            $this->markTerminalState(
                $message,
                'failed',
                'dispatch_validation_failed',
                [
                    'errors' => $exception->errors(),
                ],
            );

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
            } catch (ValidationException $exception) {
                $this->markTerminalState(
                    $message,
                    'failed',
                    'target_resolution_failed',
                    [
                        'delivery_topology' => $topology,
                        'errors' => $exception->errors(),
                    ],
                );

                return;
            }
        } elseif ($topology === PushAudienceTopologyClassifier::CHANNEL_TOPIC) {
            $requestedUnits = 1;
        }

        if ($this->scope === 'account' && $this->accountId !== null) {
            if (! $pushPlanPolicy->canSend($this->accountId, $message, $requestedUnits)) {
                $this->markTerminalState(
                    $message,
                    'failed',
                    'quota_denied',
                    [
                        'delivery_topology' => $topology,
                        'requested_units' => $requestedUnits,
                    ],
                );

                return;
            }
        }

        $acceptedCount = 0;
        $delivered = false;

        try {
            if ($topology === PushAudienceTopologyClassifier::INDIVIDUAL_DIRECT) {
                if ($requestedUnits === 0) {
                    $this->markTerminalState(
                        $message,
                        'failed',
                        'no_targets',
                        [
                            'delivery_topology' => $topology,
                            'requested_units' => $requestedUnits,
                        ],
                    );

                    return;
                }

                $recipientResolver->streamResolvedTargetBatches(
                    $message,
                    $this->scope,
                    $this->accountId,
                    500,
                    function (array $batch) use ($deliveryService, $message, &$acceptedCount, &$delivered, $users, $pushDeviceService): void {
                        $response = $deliveryService->deliver(
                            $message,
                            $batch['tokens'],
                            $batch['token_user_map']
                        );
                        if ($users !== null && $pushDeviceService !== null) {
                            $this->invalidateNotFoundTokens(
                                $response,
                                is_array($batch['token_user_map'] ?? null) ? $batch['token_user_map'] : [],
                                $users,
                                $pushDeviceService,
                            );
                        }
                        $batchAcceptedCount = (int) ($response['accepted_count'] ?? 0);
                        $acceptedCount += $batchAcceptedCount;
                        $delivered = $delivered || $batchAcceptedCount > 0;
                    }
                );
            } elseif ($topology === PushAudienceTopologyClassifier::CHANNEL_TOPIC) {
                $topic = $channelTargetResolver->resolveTopic($message);
                if (! is_string($topic) || trim($topic) === '') {
                    $this->markTerminalState(
                        $message,
                        'failed',
                        'missing_topic',
                        [
                            'delivery_topology' => $topology,
                        ],
                    );

                    return;
                }

                $response = $deliveryService->deliverToTopic($message, $topic);
                $acceptedCount = (int) ($response['accepted_count'] ?? 0);
                $delivered = $acceptedCount > 0;
            }
        } catch (ValidationException $exception) {
            $this->markTerminalState(
                $message,
                'failed',
                'delivery_validation_failed',
                [
                    'delivery_topology' => $topology,
                    'errors' => $exception->errors(),
                ],
            );

            return;
        }

        if (! $delivered) {
            $this->markTerminalState(
                $message,
                'failed',
                'delivery_failed',
                [
                    'delivery_topology' => $topology,
                    'requested_units' => $requestedUnits,
                    'accepted_count' => $acceptedCount,
                ],
            );

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

        $delivery = is_array($message->delivery ?? null) ? $message->delivery : [];
        unset($delivery['last_terminal_state']);

        $message->delivery = $delivery;
        $message->metrics = $metrics;
        $message->status = 'sent';
        $message->sent_at = Carbon::now();
        $message->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function markTerminalState(
        PushMessage $message,
        string $status,
        string $reason,
        array $context = [],
    ): void {
        if ($message->status === 'sent' || $message->sent_at !== null) {
            return;
        }

        $delivery = is_array($message->delivery ?? null) ? $message->delivery : [];
        $delivery['last_terminal_state'] = [
            'status' => $status,
            'reason' => $reason,
            'recorded_at' => Carbon::now()->toISOString(),
            'scope' => $this->scope,
            'account_id' => $this->accountId,
            'context' => $context,
        ];

        $message->delivery = $delivery;
        $message->status = $status;
        $message->sent_at = null;
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

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $tokenUserMap
     */
    private function invalidateNotFoundTokens(
        array $response,
        array $tokenUserMap,
        PushUserGatewayContract $users,
        PushDeviceService $pushDeviceService,
    ): void {
        if ($tokenUserMap === []) {
            return;
        }

        $responses = $response['responses'] ?? [];
        if (! is_array($responses)) {
            return;
        }

        $tokensByUserId = [];
        foreach ($responses as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $errorCode = trim((string) ($entry['error_code'] ?? ''));
            $token = trim((string) ($entry['token'] ?? ''));
            if ($errorCode !== 'NOT_FOUND' || $token === '') {
                continue;
            }

            $userId = trim((string) ($tokenUserMap[$token] ?? ''));
            if ($userId === '') {
                continue;
            }

            $tokensByUserId[$userId][$token] = true;
        }

        foreach ($tokensByUserId as $userId => $tokenMap) {
            $user = $this->resolvePushUser($users, $userId);
            if (! $user instanceof Authenticatable) {
                continue;
            }

            $pushDeviceService->invalidateTokens($user, array_keys($tokenMap));
        }
    }

    private function resolvePushUser(PushUserGatewayContract $users, string $userId): ?Authenticatable
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        if ($this->scope === 'account' && $this->accountId !== null) {
            return $users->findUserForAccount($this->accountId, $userId, null);
        }

        return $users->findUserForTenant($userId, null);
    }
}
