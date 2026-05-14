<?php

declare(strict_types=1);

namespace App\Application\Push;

use Belluga\PushHandler\Contracts\PushChannelTargetResolverContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;

class PushChannelTargetResolverService implements PushChannelTargetResolverContract
{
    public function __construct(
        private readonly PushChannelNamingService $naming,
    ) {}

    public function resolveTopic(PushMessage $message): ?string
    {
        $audience = is_array($message->audience ?? null) ? $message->audience : [];
        $type = trim((string) ($audience['type'] ?? ''));

        return match ($type) {
            'all_users' => $this->emptyToNull($this->naming->allUsersTopic()),
            'favorite_account_profile' => $this->emptyToNull(
                $this->naming->favoriteAccountProfileTopic((string) ($audience['account_profile_id'] ?? ''))
            ),
            'event_confirmed' => $this->emptyToNull(
                $this->naming->confirmedEventTopic((string) ($audience['event_id'] ?? ''))
            ),
            default => null,
        };
    }

    private function emptyToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
