<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Services\PushCredentialService;
use Belluga\PushHandler\Services\PushMessageService;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Illuminate\Contracts\Auth\Authenticatable;

class ContactEnteredAppPushDeliveryService
{
    public function __construct(
        private readonly PushSettingsKernelBridge $pushSettings,
        private readonly PushCredentialService $credentials,
        private readonly PushMessageService $pushMessages,
        private readonly PushUserGatewayContract $users,
    ) {}

    /**
     * @param  array<int, string>  $importerUserIds
     */
    public function sendToImporters(array $importerUserIds, AccountUser $enteredUser): void
    {
        if (! $this->isRuntimeReady()) {
            return;
        }

        $enteredUserId = trim((string) ($enteredUser->_id ?? $enteredUser->getKey() ?? ''));
        if ($enteredUserId === '') {
            return;
        }

        $recipientIds = collect($importerUserIds)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->reject(static fn (string $value): bool => $value === $enteredUserId)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $notification = $this->notificationCopy($enteredUser);

        foreach ($recipientIds as $recipientUserId) {
            $recipient = $this->users->findUserForTenant($recipientUserId, null);
            if (! $recipient instanceof Authenticatable) {
                continue;
            }

            if ($this->users->activePushTokens($recipient) === []) {
                continue;
            }

            $this->pushMessages->create('tenant', null, [
                'internal_name' => sprintf('contact-entered-app-%s-%s', $enteredUserId, $recipientUserId),
                'title_template' => $notification['title'],
                'body_template' => $notification['body'],
                'type' => 'contact_entered_app',
                'audience' => [
                    'type' => 'users',
                    'user_ids' => [$recipientUserId],
                ],
                'delivery' => [],
                'fcm_options' => [
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
                        'event' => 'contact_entered_app',
                        'push_type' => 'contact_entered_app',
                        'matched_user_id' => $enteredUserId,
                        'matched_user_display_name' => $notification['display_name'],
                    ],
                ],
            ]);
        }
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
     * @return array{title:string,body:string,display_name:string}
     */
    private function notificationCopy(AccountUser $enteredUser): array
    {
        $displayName = trim((string) ($enteredUser->name ?? ''));

        return [
            'title' => 'Contato entrou no app',
            'body' => $displayName !== ''
                ? $displayName.' entrou no app.'
                : 'Um contato seu entrou no app.',
            'display_name' => $displayName,
        ];
    }
}
