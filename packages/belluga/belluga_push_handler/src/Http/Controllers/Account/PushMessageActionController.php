<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Http\Requests\PushMessageActionRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushMetricsService;
use Illuminate\Http\JsonResponse;

class PushMessageActionController
{
    public function __construct(
        private readonly PushMetricsService $metricsService,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushAccountContextContract $accountContext,
        private readonly PushUserGatewayContract $users
    ) {}

    public function store(PushMessageActionRequest $request): JsonResponse
    {
        $accountId = $this->accountContext->currentAccountId();
        if ($accountId === null || $accountId === '') {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('_id', $pushMessageId)
            ->where('partner_id', $accountId)
            ->firstOrFail();

        $user = $request->user();
        if (! $user || ! $this->users->supports($user)) {
            return response()->json(['ok' => false], 401);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'account',
            'account_id' => $accountId,
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $payload = $request->validated();
        $userId = $this->users->userId($user);
        if ($userId === null || $userId === '') {
            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        $action = $this->metricsService->recordAction(
            $message,
            $payload,
            $userId
        );

        return response()->json([
            'ok' => true,
            'data' => $action,
        ]);
    }
}
