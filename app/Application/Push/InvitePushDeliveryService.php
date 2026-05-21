<?php

declare(strict_types=1);

namespace App\Application\Push;

use Belluga\Invites\Application\Preview\InvitePreviewPayloadFactory;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
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
        private readonly InviteIdentityGatewayContract $identities,
    ) {}

    public function sendDirectInvite(InviteEdge $edge): void
    {
        if (! $this->isDirectInviteDeliverable($edge)) {
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
            'fcm_options' => $this->inviteFcmOptions(
                invitePayload: $invitePayload,
                notification: $notification,
                pushType: 'invite_received',
                extraData: [],
            ),
        ]);
    }

    public function sendAcceptedInvite(InviteEdge $edge): void
    {
        if (! $this->isAcceptedInviteDeliverable($edge)) {
            return;
        }

        $senderUserId = trim((string) $edge->issued_by_user_id);
        $recipient = $this->users->findUserForTenant($senderUserId, null);
        if (! $recipient instanceof Authenticatable) {
            return;
        }

        if ($this->users->activePushTokens($recipient) === []) {
            return;
        }

        $invitePayload = $this->previewPayloads->fromInviteEdge($edge);
        $notification = $this->acceptedNotificationCopy($edge, $invitePayload);

        $this->pushMessages->create('tenant', null, [
            'internal_name' => 'invite-accepted-'.(string) $edge->getAttribute('_id'),
            'title_template' => $notification['title'],
            'body_template' => $notification['body'],
            'type' => 'invite_accepted',
            'audience' => [
                'type' => 'users',
                'user_ids' => [$senderUserId],
            ],
            'delivery' => [],
            'fcm_options' => $this->inviteFcmOptions(
                invitePayload: $invitePayload,
                notification: $notification,
                pushType: 'invite_accepted',
                extraData: $this->acceptedByFcmData($edge),
            ),
        ]);
    }

    private function isDirectInviteDeliverable(InviteEdge $edge): bool
    {
        if (! in_array((string) ($edge->status ?? ''), ['pending', 'viewed'], true)) {
            return false;
        }

        return $this->isRuntimeReady();
    }

    private function isAcceptedInviteDeliverable(InviteEdge $edge): bool
    {
        if ((string) ($edge->status ?? '') !== 'accepted' || ! (bool) ($edge->credited_acceptance ?? false)) {
            return false;
        }

        return $this->isRuntimeReady();
    }

    private function isRuntimeReady(): bool
    {
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

    /**
     * @param  array<string, mixed>  $invitePayload
     * @param  array{title:string,body:string}  $notification
     * @return array<string, mixed>
     */
    private function inviteFcmOptions(
        array $invitePayload,
        array $notification,
        string $pushType,
        array $extraData,
    ): array {
        $eventImageUrl = trim((string) ($invitePayload['event_image_url'] ?? ''));

        $options = [
            'notification' => [
                'title' => $notification['title'],
                'body' => $notification['body'],
            ],
            'android' => [
                'notification' => [
                    'icon' => 'ic_notification_invite',
                ],
            ],
            'data' => [
                'event' => $pushType,
                'invite_id' => trim((string) ($invitePayload['id'] ?? '')),
                'event_id' => trim((string) ($invitePayload['event_id'] ?? '')),
                'occurrence_id' => trim((string) ($invitePayload['occurrence_id'] ?? '')),
                'push_type' => $pushType,
                'event_image_url' => $eventImageUrl,
                'inviter_name' => trim((string) ($invitePayload['inviter_name'] ?? '')),
                'inviter_avatar_url' => trim((string) ($invitePayload['inviter_avatar_url'] ?? '')),
                ...$extraData,
            ],
        ];

        if ($eventImageUrl !== '') {
            $options['notification']['image'] = $eventImageUrl;
            $options['android']['notification']['image'] = $eventImageUrl;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $invitePayload
     * @return array{title:string,body:string,receiver_name:string}
     */
    private function acceptedNotificationCopy(InviteEdge $edge, array $invitePayload): array
    {
        $eventName = trim((string) ($invitePayload['event_name'] ?? ''));
        $receiverName = $this->receiverDisplayName($edge);

        $title = 'Seu convite foi aceito';

        if ($receiverName !== '' && $eventName !== '') {
            $body = $receiverName.' aceitou seu convite para '.$eventName.'.';
        } elseif ($eventName !== '') {
            $body = 'Seu convite para '.$eventName.' foi aceito.';
        } elseif ($receiverName !== '') {
            $body = $receiverName.' aceitou seu convite.';
        } else {
            $body = 'Seu convite foi aceito.';
        }

        return [
            'title' => $title,
            'body' => $body,
            'receiver_name' => $receiverName,
        ];
    }

    /**
     * @return array{
     *     accepted_by_user_id:string,
     *     accepted_by_account_profile_id:string,
     *     accepted_by_display_name:string,
     *     accepted_by_avatar_url:string
     * }
     */
    private function acceptedByFcmData(InviteEdge $edge): array
    {
        $receiverAccountProfileId = trim((string) ($edge->receiver_account_profile_id ?? ''));
        $resolved = $receiverAccountProfileId === ''
            ? null
            : $this->identities->resolveAccountProfileRecipient($receiverAccountProfileId);

        return [
            'accepted_by_user_id' => trim((string) ($edge->receiver_user_id ?? '')),
            'accepted_by_account_profile_id' => $receiverAccountProfileId,
            'accepted_by_display_name' => trim((string) ($resolved['display_name'] ?? '')),
            'accepted_by_avatar_url' => trim((string) ($resolved['avatar_url'] ?? '')),
        ];
    }

    private function receiverDisplayName(InviteEdge $edge): string
    {
        $receiverAccountProfileId = trim((string) ($edge->receiver_account_profile_id ?? ''));
        if ($receiverAccountProfileId === '') {
            return '';
        }

        $resolved = $this->identities->resolveAccountProfileRecipient($receiverAccountProfileId);
        if (! is_array($resolved)) {
            return '';
        }

        return trim((string) ($resolved['display_name'] ?? ''));
    }
}
