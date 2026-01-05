<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use Belluga\PushHandler\Http\Requests\PushMessageStoreRequest;
use Belluga\PushHandler\Http\Requests\PushMessageUpdateRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Belluga\PushHandler\Contracts\PushPlanPolicyContract;
use Belluga\PushHandler\Contracts\PushPlanPolicyDecisionContract;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushMessageController
{
    public function __construct(
        private readonly PushMessageService $service,
        private readonly PushMessageAudienceService $audienceService,
        private readonly PushPlanPolicyContract $planPolicy
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $query = PushMessage::query()
            ->where('scope', 'account')
            ->where('partner_id', (string) $account->_id);

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
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('_id', $pushMessageId)
            ->where('partner_id', (string) $account->_id)
            ->firstOrFail();

        return response()->json(['data' => $message]);
    }

    public function store(PushMessageStoreRequest $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $payload = $request->validated();

        $expiresAt = $payload['delivery']['expires_at'] ?? null;
        $maxTtlDays = TenantPushSettings::current()?->getPushMaxTtlDays() ?? 30;
        if ($expiresAt && now()->addDays($maxTtlDays)->lt(\Carbon\Carbon::parse($expiresAt))) {
            return response()->json([
                'message' => 'expires_at exceeds max TTL.',
                'errors' => ['delivery.expires_at' => "Must be within $maxTtlDays days."],
            ], 422);
        }

        if ($expiresAt) {
            $payload['delivery']['ttl_minutes'] = now()->diffInMinutes(\Carbon\Carbon::parse($expiresAt), false);
        }

        $exists = PushMessage::query()
            ->where('scope', 'account')
            ->where('partner_id', (string) $account->_id)
            ->where('internal_name', $payload['internal_name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'internal_name already exists for this account.',
                'errors' => ['internal_name' => 'Must be unique per account.'],
            ], 422);
        }

        $message = $this->service->create('account', (string) $account->_id, $payload);

        $response = ['data' => $message];
        if ($this->planPolicy instanceof PushPlanPolicyDecisionContract) {
            $audienceSize = $this->audienceService->audienceSize($message);
            $response['quota_decision'] = $this->planPolicy->quotaDecision(
                (string) $account->_id,
                $message,
                $audienceSize
            );
        }

        return response()->json($response, 201);
    }

    public function update(PushMessageUpdateRequest $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('_id', $pushMessageId)
            ->where('partner_id', (string) $account->_id)
            ->firstOrFail();

        $payload = $request->validated();

        $expiresAt = $payload['delivery']['expires_at'] ?? null;
        $maxTtlDays = TenantPushSettings::current()?->getPushMaxTtlDays() ?? 30;
        if ($expiresAt && now()->addDays($maxTtlDays)->lt(\Carbon\Carbon::parse($expiresAt))) {
            return response()->json([
                'message' => 'expires_at exceeds max TTL.',
                'errors' => ['delivery.expires_at' => "Must be within $maxTtlDays days."],
            ], 422);
        }

        if (isset($payload['internal_name'])) {
            $exists = PushMessage::query()
                ->where('scope', 'account')
                ->where('partner_id', (string) $account->_id)
                ->where('internal_name', $payload['internal_name'])
                ->where('_id', '!=', $pushMessageId)
                ->exists();

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
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('_id', $pushMessageId)
            ->where('partner_id', (string) $account->_id)
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
