<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Http\Requests\PushQuotaCheckRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Http\JsonResponse;

class PushQuotaCheckController
{
    public function __construct(
        private readonly PushPlanPolicyContract $planPolicy
    ) {
    }

    public function __invoke(PushQuotaCheckRequest $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $payload = $request->validated();
        $audienceSize = (int) $payload['audience_size'];

        $message = null;
        if (! empty($payload['push_message_id'])) {
            $message = PushMessage::query()
                ->where('scope', 'account')
                ->where('partner_id', (string) $account->_id)
                ->where('_id', (string) $payload['push_message_id'])
                ->first();
        }

        $message ??= new PushMessage([
            'type' => $payload['message_type'] ?? null,
        ]);

        $policy = $this->planPolicy;

        if ($policy instanceof PushPlanPolicyDecisionContract) {
            return response()->json($policy->quotaDecision((string) $account->_id, $message, $audienceSize));
        }

        $allowed = $policy->canSend((string) $account->_id, $message, $audienceSize);

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
