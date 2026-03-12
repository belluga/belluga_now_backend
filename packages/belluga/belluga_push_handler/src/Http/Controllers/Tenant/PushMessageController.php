<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\PushMessageStoreRequest;
use Belluga\PushHandler\Http\Requests\PushMessageUpdateRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushMessageController
{
    public function __construct(
        private readonly PushMessageService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PushMessage::query()->where('scope', 'tenant');

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
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->firstOrFail();

        return response()->json(['data' => $message]);
    }

    public function store(PushMessageStoreRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $exists = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('internal_name', $payload['internal_name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'internal_name already exists for this tenant.',
                'errors' => ['internal_name' => 'Must be unique per tenant.'],
            ], 422);
        }

        $message = $this->service->create('tenant', null, $payload);

        return response()->json(['data' => $message], 201);
    }

    public function update(PushMessageUpdateRequest $request): JsonResponse
    {
        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->firstOrFail();

        $payload = $request->validated();

        if (isset($payload['internal_name'])) {
            $exists = PushMessage::query()
                ->where('scope', 'tenant')
                ->where('internal_name', $payload['internal_name'])
                ->where('_id', '!=', $pushMessageId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'internal_name already exists for this tenant.',
                    'errors' => ['internal_name' => 'Must be unique per tenant.'],
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
        $message = PushMessage::query()
            ->where('scope', 'tenant')
            ->where('_id', $pushMessageId)
            ->firstOrFail();

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
