<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushMessageRenderer;
use Belluga\PushHandler\Services\PushMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushMessageDataController
{
    public function __construct(
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushMessageRenderer $renderer,
        private readonly PushMetricsService $metricsService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()->where('_id', $pushMessageId)->first();

        if (! $message) {
            return response()->json(['ok' => false, 'reason' => 'not_found']);
        }
        if ($message->scope !== 'account') {
            return response()->json(['ok' => false, 'reason' => 'not_found']);
        }
        if ((string) $message->partner_id !== (string) $account->_id) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        if (! $message->isActive()) {
            return response()->json(['ok' => false, 'reason' => 'inactive']);
        }

        if ($message->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'expired']);
        }

        $user = $request->user();
        if (! $user instanceof AccountUser) {
            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'account',
            'account_id' => (string) $account->_id,
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $payload = $this->renderer->render($message, [
            'user' => $user,
        ]);

        $this->metricsService->recordAction($message, [
            'action' => 'opened',
            'step_index' => 0,
            'idempotency_key' => 'opened:' . (string) $user->_id . ':' . (string) $message->_id,
        ], (string) $user->_id);

        return response()->json([
            'ok' => true,
            'push_message_id' => (string) $message->_id,
            'payload' => $payload,
        ]);
    }
}
