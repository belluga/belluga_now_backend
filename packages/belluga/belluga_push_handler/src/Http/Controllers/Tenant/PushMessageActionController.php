<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Http\Requests\PushMessageActionRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushMetricsService;
use Illuminate\Http\JsonResponse;

class PushMessageActionController
{
    public function __construct(
        private readonly PushMetricsService $metricsService,
        private readonly PushMessageAudienceService $audienceService
    ) {
    }

    public function store(PushMessageActionRequest $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->firstOrFail();

        $user = $request->user();
        if (! $user instanceof AccountUser) {
            return response()->json(['ok' => false], 401);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'tenant',
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $payload = $request->validated();

        $action = $this->metricsService->recordAction($message, $payload, (string) $user->_id);

        return response()->json([
            'ok' => true,
            'data' => $action,
        ]);
    }
}
