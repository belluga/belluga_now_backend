<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Services;

class PushAudienceCanonicalizer
{
    /**
     * @param  array<string, mixed>  $audience
     * @return array<string, mixed>
     */
    public function canonicalize(array $audience): array
    {
        $type = trim((string) ($audience['type'] ?? ''));

        return match ($type) {
            'users' => [
                'type' => 'users',
                'user_ids' => $this->normalizedUserIds($audience['user_ids'] ?? []),
            ],
            'all_users' => [
                'type' => 'all_users',
            ],
            'favorite_account_profile' => [
                'type' => 'favorite_account_profile',
                'account_profile_id' => trim((string) ($audience['account_profile_id'] ?? '')),
            ],
            'event', 'event_confirmed' => [
                'type' => 'event_confirmed',
                'event_id' => trim((string) ($audience['event_id'] ?? '')),
            ],
            default => array_filter([
                'type' => $type,
                'event_id' => isset($audience['event_id']) ? trim((string) $audience['event_id']) : null,
                'account_profile_id' => isset($audience['account_profile_id'])
                    ? trim((string) $audience['account_profile_id'])
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
        };
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
