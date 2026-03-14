<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Feed;

use Belluga\Invites\Application\Async\InviteOutboxEmitter;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Illuminate\Support\Carbon;

class InviteProjectionService
{
    public function __construct(
        private readonly InviteOutboxEmitter $outboxEmitter,
        private readonly PrincipalSocialMetricsService $metricsService,
    ) {}

    /**
     * @param  array{event_id:string,occurrence_id:?string}  $targetRef
     */
    public function rebuildReceiverTargetProjection(string $receiverUserId, array $targetRef): void
    {
        $query = InviteEdge::query()
            ->where('receiver_user_id', $receiverUserId)
            ->where('event_id', $targetRef['event_id'])
            ->where('occurrence_id', $targetRef['occurrence_id'])
            ->whereIn('status', ['pending', 'viewed'])
            ->orderBy('created_at');

        $pendingEdges = $query->get();
        $groupKey = $this->groupKey($targetRef['event_id'], $targetRef['occurrence_id']);

        if ($pendingEdges->isEmpty()) {
            InviteFeedProjection::query()
                ->where('receiver_user_id', $receiverUserId)
                ->where('group_key', $groupKey)
                ->delete();

            $timestamp = Carbon::now()->toISOString();
            $this->outboxEmitter->emit(
                topic: 'invite.deleted',
                payload: [
                    'type' => 'invite.deleted',
                    'target_ref' => $targetRef,
                    'updated_at' => $timestamp,
                ],
                receiverUserId: $receiverUserId
            );
            $this->metricsService->syncPendingInvitesReceived($receiverUserId, $this->pendingGroupCount($receiverUserId));

            return;
        }

        /** @var InviteEdge $primary */
        $primary = $pendingEdges->first();
        /** @var InviteFeedProjection|null $projection */
        $projection = InviteFeedProjection::query()
            ->where('receiver_user_id', $receiverUserId)
            ->where('group_key', $groupKey)
            ->first();
        $projection ??= new InviteFeedProjection([
            'receiver_user_id' => $receiverUserId,
            'group_key' => $groupKey,
        ]);

        $projection->fill([
            'event_id' => (string) $primary->event_id,
            'occurrence_id' => $primary->occurrence_id ? (string) $primary->occurrence_id : null,
            'event_name' => (string) ($primary->event_name ?? ''),
            'event_slug' => (string) ($primary->event_slug ?? ''),
            'event_date' => $primary->event_date,
            'event_image_url' => $primary->event_image_url,
            'location' => (string) ($primary->location_label ?? ''),
            'host_name' => (string) ($primary->host_name ?? ''),
            'message' => (string) ($primary->message ?? ''),
            'tags' => is_array($primary->tags) ? array_values(array_map('strval', $primary->tags)) : [],
            'attendance_policy' => (string) ($primary->attendance_policy ?? 'free_confirmation_only'),
            'inviter_candidates' => $pendingEdges
                ->map(static function (InviteEdge $edge): array {
                    $principal = is_array($edge->inviter_principal) ? $edge->inviter_principal : [];

                    return [
                        'invite_id' => (string) $edge->_id,
                        'inviter_principal' => [
                            'kind' => (string) ($principal['kind'] ?? ''),
                            'id' => (string) ($principal['principal_id'] ?? $principal['id'] ?? ''),
                        ],
                        'display_name' => $edge->inviter_display_name,
                        'avatar_url' => $edge->inviter_avatar_url,
                        'status' => (string) ($edge->status ?? 'pending'),
                    ];
                })
                ->values()
                ->all(),
            'social_proof' => [
                'additional_inviter_count' => max(0, $pendingEdges->count() - 1),
            ],
        ]);
        $projection->save();

        $timestamp = $projection->updated_at?->toISOString() ?? Carbon::now()->toISOString();
        $this->outboxEmitter->emit(
            topic: 'invite.upsert',
            payload: [
                'type' => 'invite.upsert',
                'invite' => $this->toFeedPayload($projection),
                'updated_at' => $timestamp,
            ],
            receiverUserId: $receiverUserId
        );
        $this->metricsService->syncPendingInvitesReceived($receiverUserId, $this->pendingGroupCount($receiverUserId));
    }

    /**
     * @return array<string, mixed>
     */
    public function toFeedPayload(InviteFeedProjection $projection): array
    {
        return [
            'target_ref' => [
                'event_id' => (string) ($projection->event_id ?? ''),
                'occurrence_id' => $projection->occurrence_id ? (string) $projection->occurrence_id : null,
            ],
            'event_name' => (string) ($projection->event_name ?? ''),
            'event_date' => $projection->event_date?->toISOString(),
            'event_image_url' => $projection->event_image_url,
            'location' => (string) ($projection->location ?? ''),
            'host_name' => (string) ($projection->host_name ?? ''),
            'message' => (string) ($projection->message ?? ''),
            'tags' => is_array($projection->tags) ? array_values(array_map('strval', $projection->tags)) : [],
            'attendance_policy' => (string) ($projection->attendance_policy ?? 'free_confirmation_only'),
            'inviter_candidates' => is_array($projection->inviter_candidates) ? $projection->inviter_candidates : [],
            'social_proof' => is_array($projection->social_proof) ? $projection->social_proof : ['additional_inviter_count' => 0],
        ];
    }

    private function pendingGroupCount(string $receiverUserId): int
    {
        return InviteFeedProjection::query()
            ->where('receiver_user_id', $receiverUserId)
            ->count();
    }

    private function groupKey(string $eventId, ?string $occurrenceId): string
    {
        return $eventId.'::'.($occurrenceId ?? 'event');
    }
}
