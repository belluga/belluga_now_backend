<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Http\Requests\PushQuotaCheckRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Http\JsonResponse;

class PushQuotaCheckController
{
    public function __construct(
        private readonly PushPlanPolicyContract $planPolicy,
        private readonly PushAccountContextContract $accountContext
    ) {}

    public function __invoke(PushQuotaCheckRequest $request): JsonResponse
    {
        $accountId = $this->accountContext->currentAccountId();
        if ($accountId === null || $accountId === '') {
            abort(422, 'Account context not available.');
        }

        $payload = $request->validated();
        $audienceSize = (int) $payload['audience_size'];

        $message = null;
        if (! empty($payload['push_message_id'])) {
            $message = PushMessage::query()
                ->where('scope', 'account')
                ->where('partner_id', $accountId)
                ->where('_id', (string) $payload['push_message_id'])
                ->first();
        }

        $message ??= new PushMessage([
            'type' => $payload['message_type'] ?? null,
        ]);

        $policy = $this->planPolicy;

        if ($policy instanceof PushPlanPolicyDecisionContract) {
            return response()->json($policy->quotaDecision($accountId, $message, $audienceSize));
        }

        $allowed = $policy->canSend($accountId, $message, $audienceSize);

        return response()->json([
            'allowed' => $allowed,
            'limit' => null,
            'current_used' => null,
            'requested' => $audienceSize,
            'remaining_after' => null,
            'period' => null,
            'reason' => $allowed ? null : 'quota_exceeded',
        ]);
    }
}
