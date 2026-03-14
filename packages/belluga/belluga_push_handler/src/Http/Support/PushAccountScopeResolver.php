<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Support;

use Belluga\PushHandler\Contracts\PushAccountContextContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Database\Eloquent\Builder;

class PushAccountScopeResolver
{
    public function __construct(
        private readonly PushAccountContextContract $accountContext
    ) {}

    public function currentAccountId(): ?string
    {
        return $this->accountContext->currentAccountId();
    }

    public function scopedMessageQuery(string $accountId): Builder
    {
        return PushMessage::query()
            ->where('scope', 'account')
            ->where('partner_id', $accountId);
    }

    public function findMessage(string $accountId, string $pushMessageId): ?PushMessage
    {
        return $this->scopedMessageQuery($accountId)
            ->where('_id', $pushMessageId)
            ->first();
    }

    public function findMessageOrFail(string $accountId, string $pushMessageId): PushMessage
    {
        return $this->scopedMessageQuery($accountId)
            ->where('_id', $pushMessageId)
            ->firstOrFail();
    }

    public function anyMessageExists(string $pushMessageId): bool
    {
        return PushMessage::query()
            ->where('_id', $pushMessageId)
            ->exists();
    }

    public function internalNameExists(string $accountId, string $internalName, ?string $exceptPushMessageId = null): bool
    {
        $query = $this->scopedMessageQuery($accountId)
            ->where('internal_name', $internalName);

        if (is_string($exceptPushMessageId) && $exceptPushMessageId !== '') {
            $query->where('_id', '!=', $exceptPushMessageId);
        }

        return $query->exists();
    }
}
