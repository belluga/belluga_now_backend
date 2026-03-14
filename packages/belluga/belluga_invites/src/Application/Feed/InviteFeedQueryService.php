<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Feed;

use Belluga\Invites\Application\Settings\InviteRuntimeSettingsService;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Illuminate\Support\Facades\Context;

class InviteFeedQueryService
{
    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly InviteProjectionService $projectionService,
        private readonly InviteRuntimeSettingsService $runtimeSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetchForUser(string $userId, int $page = 1, int $pageSize = self::DEFAULT_PAGE_SIZE): array
    {
        $normalizedPage = max(1, $page);
        $normalizedPageSize = max(1, min($pageSize, 50));
        $skip = ($normalizedPage - 1) * $normalizedPageSize;
        $limit = $normalizedPageSize + 1;

        $items = InviteFeedProjection::query()
            ->where('receiver_user_id', $userId)
            ->orderBy('event_date')
            ->skip($skip)
            ->limit($limit)
            ->get();

        $hasMore = $items->count() > $normalizedPageSize;
        $pageSlice = $items->take($normalizedPageSize);

        return [
            'tenant_id' => $this->currentTenantId(),
            'invites' => $pageSlice
                ->map(fn (InviteFeedProjection $projection): array => $this->projectionService->toFeedPayload($projection))
                ->values()
                ->all(),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        return $this->runtimeSettings->settingsPayload();
    }

    private function currentTenantId(): ?string
    {
        $tenantId = Context::get('tenantId');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
