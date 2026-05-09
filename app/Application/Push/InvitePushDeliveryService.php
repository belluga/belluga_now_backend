<?php

declare(strict_types=1);

namespace App\Application\Push;

use Belluga\Invites\Application\Preview\InvitePreviewPayloadFactory;
use Belluga\Invites\Contracts\InvitePushDeliveryContract;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushMessageService;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Illuminate\Contracts\Auth\Authenticatable;

class InvitePushDeliveryService implements InvitePushDeliveryContract
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly PushCredentialService $credentials,
        private readonly PushMessageService $pushMessages,
        private readonly PushUserGatewayContract $users,
        private readonly InvitePreviewPayloadFactory $previewPayloads,
    ) {}

    public function sendDirectInvite(InviteEdge $edge): void
    {
        if (! $this->shouldDeliver($edge)) {
            return;
        }

        $receiverUserId = trim((string) $edge->receiver_user_id);
        $recipient = $this->users->findUserForTenant($receiverUserId, null);
        if (! $recipient instanceof Authenticatable) {
            return;
        }

        if ($this->users->activePushTokens($recipient) === []) {
            return;
        }

        $invitePayload = $this->previewPayloads->fromInviteEdge($edge);
        $notification = $this->notificationCopy($invitePayload);

        $this->pushMessages->create('tenant', null, [
            'internal_name' => 'invite-received-'.(string) $edge->getAttribute('_id'),
            'title_template' => $notification['title'],
            'body_template' => $notification['body'],
            'type' => 'invite_received',
            'audience' => [
                'type' => 'users',
                'user_ids' => [$receiverUserId],
            ],
            'delivery' => [],
            'payload_template' => [
                'layoutType' => 'fullScreen',
                'closeBehavior' => 'after_action',
                'title' => $notification['title'],
                'body' => $notification['body'],
                'steps' => [[
                    'slug' => 'invite-received',
                    'type' => 'copy',
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                ]],
                'buttons' => [],
                'invite' => $invitePayload,
                'invites' => [$invitePayload],
            ],
            'fcm_options' => [
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                ],
                'data' => [
                    'event' => 'invite_received',
                    'invite_id' => (string) ($invitePayload['id'] ?? ''),
                    'event_id' => (string) ($invitePayload['event_id'] ?? ''),
                    'occurrence_id' => (string) ($invitePayload['occurrence_id'] ?? ''),
                    'push_type' => 'invite_received',
                ],
            ],
        ]);
    }

    private function shouldDeliver(InviteEdge $edge): bool
    {
        if (! in_array((string) ($edge->status ?? ''), ['pending', 'viewed'], true)) {
            return false;
        }

        $push = $this->pushSettings->resolvedPushConfig();
        if (($push['enabled'] ?? false) !== true) {
            return false;
        }

        if (! $this->pushSettings->hasRequiredFirebaseConfig($this->pushSettings->currentFirebaseConfig())) {
            return false;
        }

        try {
            return $this->credentials->current() !== null;
        } catch (MultiplePushCredentialsException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $invitePayload
     * @return array{title:string,body:string}
     */
    private function notificationCopy(array $invitePayload): array
    {
        $eventName = trim((string) ($invitePayload['event_name'] ?? ''));
        $location = trim((string) ($invitePayload['location'] ?? ''));
        $inviterName = trim((string) data_get($invitePayload, 'inviter_candidates.0.display_name', ''));

        $title = $eventName !== ''
            ? 'Convite para '.$eventName
            : 'Voce recebeu um convite';

        if ($inviterName !== '' && $eventName !== '' && $location !== '') {
            $body = $inviterName.' convidou voce para '.$eventName.' em '.$location.'.';
        } elseif ($inviterName !== '' && $eventName !== '') {
            $body = $inviterName.' convidou voce para '.$eventName.'.';
        } elseif ($eventName !== '' && $location !== '') {
            $body = 'Novo convite para '.$eventName.' em '.$location.'.';
        } elseif ($eventName !== '') {
            $body = 'Novo convite para '.$eventName.'.';
        } else {
            $body = 'Abra o app para ver os detalhes do convite.';
        }

        return [
            'title' => $title,
            'body' => $body,
        ];
    }
}
