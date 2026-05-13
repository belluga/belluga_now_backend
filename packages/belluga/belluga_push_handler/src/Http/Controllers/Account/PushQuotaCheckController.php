<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Contracts\PushChannelAuthorizationContract;
use Belluga\PushHandler\Http\Controllers\Account\Concerns\ResolvesAccountContext;
use Belluga\PushHandler\Http\Requests\PushQuotaCheckRequest;
use Belluga\PushHandler\Http\Support\PushAccountScopeResolver;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushAudienceCanonicalizer;
use Belluga\PushHandler\Services\PushAudienceTopologyClassifier;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Illuminate\Http\JsonResponse;

class PushQuotaCheckController
{
    use ResolvesAccountContext;

    public function __construct(
        private readonly PushPlanPolicyContract $planPolicy,
        private readonly PushAccountScopeResolver $accountScope,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushAudienceCanonicalizer $audienceCanonicalizer,
        private readonly PushAudienceTopologyClassifier $audienceTopology,
        private readonly PushChannelAuthorizationContract $channelAuthorization,
    ) {}

    public function __invoke(PushQuotaCheckRequest $request): JsonResponse
    {
        $accountId = $this->requireAccountId($this->accountScope);
        $payload = $request->validated();

        $message = null;
        if (! empty($payload['push_message_id'])) {
            $message = $this->accountScope->findMessage(
                $accountId,
                (string) $payload['push_message_id']
            );
        }

        $message ??= new PushMessage([
            'type' => $payload['message_type'] ?? null,
            'scope' => 'account',
            'partner_id' => $accountId,
            'audience' => $this->audienceCanonicalizer->canonicalize(
                is_array($payload['audience'] ?? null) ? $payload['audience'] : []
            ),
        ]);

        $this->channelAuthorization->assertCanDispatch('account', $accountId, $message);
        $this->audienceTopology->assertDispatchable($message);

        $requestedUnits = $this->audienceService->requestedUnits($message);
        $policy = $this->planPolicy;

        if ($policy instanceof PushPlanPolicyDecisionContract) {
            return response()->json($policy->quotaDecision($accountId, $message, $requestedUnits));
        }

        $allowed = $policy->canSend($accountId, $message, $requestedUnits);

        return response()->json([
            'allowed' => $allowed,
            'limit' => null,
            'current_used' => null,
            'requested' => $requestedUnits,
            'remaining_after' => null,
            'period' => null,
            'reason' => $allowed ? null : 'quota_exceeded',
        ]);
    }
}
