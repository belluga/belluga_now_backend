<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Contracts\PushUserGatewayContract;
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
        private readonly PushMetricsService $metricsService,
        private readonly PushUserGatewayContract $users
    ) {}

    public function show(Request $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->first();

        if (! $message) {
            return response()->json(['ok' => false, 'reason' => 'not_found']);
        }

        if (! $message->isActive()) {
            return response()->json(['ok' => false, 'reason' => 'inactive']);
        }

        if ($message->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'expired']);
        }

        $user = $request->user();
        if (! $user || ! $this->users->supports($user)) {
            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'tenant',
        ])) {
            return response()->json(['ok' => false, 'reason' => 'not_found'], 404);
        }

        $payload = $this->renderer->render($message, [
            'user' => $user,
        ]);

        return response()->json([
            'ok' => true,
            'push_message_id' => (string) $message->_id,
            'payload' => $payload,
        ]);
    }
}
