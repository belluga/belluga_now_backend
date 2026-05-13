<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Models\Landlord\Tenant;

class PushChannelNamingService
{
    public function allUsersTopic(): string
    {
        return $this->topic('all_users', 'all_users', 'all');
    }

    public function favoriteAccountProfileTopic(string $accountProfileId): string
    {
        return $this->topic('favorite_account_profile', $accountProfileId, 'fav');
    }

    public function confirmedEventTopic(string $eventId): string
    {
        return $this->topic('event_confirmed', $eventId, 'evt');
    }

    private function topic(string $kind, string $subjectId, string $prefix): string
    {
        $tenantId = $this->currentTenantId();
        $subjectId = trim($subjectId);
        if ($tenantId === '' || $subjectId === '') {
            return '';
        }

        $secret = (string) config('app.key', '');
        if ($secret === '') {
            $secret = 'push-channel-fallback';
        }

        $digest = substr(hash_hmac('sha256', implode('|', [
            'belluga.push.channel',
            $tenantId,
            $kind,
            $subjectId,
        ]), $secret), 0, 48);

        return 'belluga_'.$prefix.'_'.$digest;
    }

    private function currentTenantId(): string
    {
        $tenant = Tenant::current();

        return trim((string) ($tenant?->_id ?? $tenant?->id ?? ''));
    }
}
