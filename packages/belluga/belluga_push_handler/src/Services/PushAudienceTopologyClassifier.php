<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Validation\ValidationException;

class PushAudienceTopologyClassifier
{
    public const INDIVIDUAL_DIRECT = 'individual_direct';

    public const CHANNEL_TOPIC = 'channel_topic';

    public const UNSUPPORTED_UNTIL_REFRAMED = 'unsupported_until_reframed';

    public function classify(PushMessage $message): string
    {
        $audience = is_array($message->audience ?? null) ? $message->audience : [];
        $audienceType = trim((string) ($audience['type'] ?? ''));

        if ($audienceType === 'users') {
            return count($this->normalizedUserIds($audience['user_ids'] ?? [])) === 1
                ? self::INDIVIDUAL_DIRECT
                : self::UNSUPPORTED_UNTIL_REFRAMED;
        }

        if (in_array($audienceType, ['all_users', 'favorite_account_profile', 'event_confirmed'], true)) {
            return self::CHANNEL_TOPIC;
        }

        return self::UNSUPPORTED_UNTIL_REFRAMED;
    }

    public function isIndividualDirect(PushMessage $message): bool
    {
        return $this->classify($message) === self::INDIVIDUAL_DIRECT;
    }

    public function isChannelTopic(PushMessage $message): bool
    {
        return $this->classify($message) === self::CHANNEL_TOPIC;
    }

    public function assertDispatchable(PushMessage $message): void
    {
        if ($this->classify($message) !== self::UNSUPPORTED_UNTIL_REFRAMED) {
            return;
        }

        throw ValidationException::withMessages([
            'audience.type' => 'This push audience is not dispatchable. Use exactly one direct user or a supported channel/topic.',
        ]);
    }

    public function assertIndividualDirect(PushMessage $message): void
    {
        if ($this->isIndividualDirect($message)) {
            return;
        }

        throw ValidationException::withMessages([
            'audience.type' => 'Transactional direct send only supports exactly one concrete private recipient.',
        ]);
    }

    public function directRecipientUserId(PushMessage $message): ?string
    {
        if (! $this->isIndividualDirect($message)) {
            return null;
        }

        $audience = is_array($message->audience ?? null) ? $message->audience : [];
        $userIds = $this->normalizedUserIds($audience['user_ids'] ?? []);

        return $userIds[0] ?? null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizedUserIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== '')));
    }
}
