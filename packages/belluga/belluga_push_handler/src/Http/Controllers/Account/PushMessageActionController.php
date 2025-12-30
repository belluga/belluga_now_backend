<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use Belluga\PushHandler\Http\Requests\PushMessageActionRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMetricsService;
use Illuminate\Http\JsonResponse;

class PushMessageActionController
{
    public function __construct(
        private readonly PushMetricsService $metricsService
    ) {
    }

    public function store(PushMessageActionRequest $request, string $push_message_id): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $message = PushMessage::query()
            ->where('_id', $push_message_id)
            ->where('partner_id', (string) $account->_id)
            ->firstOrFail();

        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $payload = $request->validated();

        $action = $this->metricsService->recordAction($message, $payload, (string) $user->_id);

        return response()->json([
            'ok' => true,
            'data' => $action,
        ]);
    }
}
