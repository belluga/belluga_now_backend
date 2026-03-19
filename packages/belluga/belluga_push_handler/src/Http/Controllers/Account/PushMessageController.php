<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Http\Controllers\Account\Concerns\ResolvesAccountContext;
use Belluga\PushHandler\Http\Requests\PushMessageStoreRequest;
use Belluga\PushHandler\Http\Requests\PushMessageUpdateRequest;
use Belluga\PushHandler\Http\Support\PushAccountScopeResolver;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushMessageController
{
    use ResolvesAccountContext;

    public function __construct(
        private readonly PushMessageService $service,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushPlanPolicyContract $planPolicy,
        private readonly PushAccountScopeResolver $accountScope
    ) {}

    public function index(Request $request): JsonResponse
    {
        $accountId = $this->requireAccountId($this->accountScope);
        $query = $this->accountScope->scopedMessageQuery($accountId);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = $this->accountScope->findMessageOrFail(
            $this->requireAccountId($this->accountScope),
            $pushMessageId
        );

        return response()->json(['data' => $message]);
    }

    public function store(PushMessageStoreRequest $request): JsonResponse
    {
        $accountId = $this->requireAccountId($this->accountScope);
        $payload = $request->validated();

        $exists = $this->accountScope->internalNameExists($accountId, (string) $payload['internal_name']);

        if ($exists) {
            return response()->json([
                'message' => 'internal_name already exists for this account.',
                'errors' => ['internal_name' => 'Must be unique per account.'],
            ], 422);
        }

        $message = $this->service->create('account', $accountId, $payload);

        $response = ['data' => $message];
        if ($this->planPolicy instanceof PushPlanPolicyDecisionContract) {
            $audienceSize = $this->audienceService->audienceSize($message);
            $response['quota_decision'] = $this->planPolicy->quotaDecision(
                $accountId,
                $message,
                $audienceSize
            );
        }

        return response()->json($response, 201);
    }

    public function update(PushMessageUpdateRequest $request): JsonResponse
    {
        $accountId = $this->requireAccountId($this->accountScope);
        $pushMessageId = (string) $request->route('push_message_id');
        $message = $this->accountScope->findMessageOrFail($accountId, $pushMessageId);

        $payload = $request->validated();

        if (isset($payload['internal_name'])) {
            $exists = $this->accountScope->internalNameExists(
                $accountId,
                (string) $payload['internal_name'],
                $pushMessageId
            );

            if ($exists) {
                return response()->json([
                    'message' => 'internal_name already exists for this account.',
                    'errors' => ['internal_name' => 'Must be unique per account.'],
                ], 422);
            }
        }

        $message->fill($payload);
        $message->save();

        return response()->json(['data' => $message]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = $this->accountScope->findMessageOrFail(
            $this->requireAccountId($this->accountScope),
            $pushMessageId
        );

        $metrics = $message->metrics ?? [];
        $wasSent = ($message->status ?? null) === 'sent' || $message->sent_at !== null;
        $wasDelivered = ($metrics['accepted_count'] ?? 0) > 0 || ($metrics['delivered_count'] ?? 0) > 0;

        if ($wasSent || $wasDelivered) {
            $message->active = false;
            $message->status = 'archived';
            $message->archived_at = now();
            $message->save();

            return response()->json(['data' => $message]);
        }

        $message->delete();

        return response()->json(['ok' => true]);
    }
}
