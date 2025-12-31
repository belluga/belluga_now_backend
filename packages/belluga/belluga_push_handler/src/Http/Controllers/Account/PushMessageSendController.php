<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Account;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Http\Requests\PushMessageSendRequest;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Services\PushDeliveryService;
use Belluga\PushHandler\Services\PushMessageAudienceService;
use Belluga\PushHandler\Services\PushRecipientResolver;
use Illuminate\Http\JsonResponse;

class PushMessageSendController
{
    public function __construct(
        private readonly PushRecipientResolver $recipientResolver,
        private readonly PushDeliveryService $deliveryService,
        private readonly PushMessageAudienceService $audienceService
    ) {
    }

    public function __invoke(PushMessageSendRequest $request): JsonResponse
    {
        $account = Account::current();
        if (! $account) {
            abort(422, 'Account context not available.');
        }

        $pushMessageId = (string) $request->route('push_message_id');
        $message = PushMessage::query()
            ->where('scope', 'account')
            ->where('partner_id', (string) $account->_id)
            ->where('_id', $pushMessageId)
            ->firstOrFail();

        if (! $message->isActive() || $message->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'inactive'], 422);
        }

        if (($message->type ?? null) !== 'transactional') {
            return response()->json(['ok' => false, 'reason' => 'invalid_type'], 422);
        }

        $payload = $request->validated();

        $user = $this->resolveUser($payload, (string) $account->_id);
        if (! $user) {
            return response()->json(['ok' => false, 'reason' => 'user_not_found'], 404);
        }

        if (! $this->audienceService->isEligible($user, $message, [
            'scope' => 'account',
            'account_id' => (string) $account->_id,
        ])) {
            return response()->json(['ok' => false, 'reason' => 'forbidden'], 403);
        }

        $tokens = $this->recipientResolver->tokensForUser($user);
        if (! empty($payload['device_id'])) {
            $tokens = array_values(array_filter($tokens, static function (string $token) use ($user, $payload): bool {
                foreach ($user->devices ?? [] as $device) {
                    if (($device['device_id'] ?? null) === $payload['device_id'] && ($device['push_token'] ?? null) === $token) {
                        return true;
                    }
                }
                return false;
            }));
        }

        if ($tokens === []) {
            return response()->json(['ok' => false, 'reason' => 'no_tokens'], 422);
        }

        if (! ($payload['dry_run'] ?? false)) {
            $response = $this->deliveryService->deliver($message, $tokens);

            $metrics = $message->metrics ?? [];
            $metrics['accepted_count'] = ($metrics['accepted_count'] ?? 0) + (int) ($response['accepted_count'] ?? 0);
            $metrics['sent_count'] = ($metrics['sent_count'] ?? 0) + 1;
            $message->metrics = $metrics;
            $message->save();
        }

        return response()->json([
            'ok' => true,
            'push_message_id' => (string) $message->_id,
            'recipient_user_id' => (string) $user->_id,
            'queued' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveUser(array $payload, string $accountId): ?AccountUser
    {
        if (! empty($payload['user_id'])) {
            return AccountUser::query()
                ->where('_id', $payload['user_id'])
                ->where('account_roles.account_id', $accountId)
                ->first();
        }

        if (! empty($payload['email'])) {
            $email = strtolower((string) $payload['email']);
            return AccountUser::query()
                ->where('emails', 'all', [$email])
                ->where('account_roles.account_id', $accountId)
                ->first();
        }

        return null;
    }
}
