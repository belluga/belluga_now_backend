<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Preview;

use Belluga\Invites\Models\Tenants\InviteEdge;
use Illuminate\Support\Carbon;

class InvitePreviewPayloadFactory
{
    /**
     * @param  array{
     *     target_ref: array{event_id:string,occurrence_id:string},
     *     event_snapshot: array{
     *         event_name:string,
     *         event_slug:string,
     *         event_date:?Carbon,
     *         event_image_url:?string,
     *         location:string,
     *         host_name:string,
     *         tags:array<int,string>,
     *         attendance_policy:string,
     *         expires_at:?Carbon
     *     }
     * }  $target
     * @param  array{kind:string,id:string}  $principal
     * @return array<string, mixed>
     */
    public function fromSharePreview(
        string $shareCode,
        array $target,
        array $principal,
        ?string $inviterDisplayName,
        ?string $inviterAvatarUrl,
    ): array {
        return $this->build(
            inviteId: 'share:'.strtoupper(trim($shareCode)),
            targetRef: $target['target_ref'],
            principal: $principal,
            inviterDisplayName: $inviterDisplayName,
            inviterAvatarUrl: $inviterAvatarUrl,
            eventName: (string) ($target['event_snapshot']['event_name'] ?? ''),
            eventDate: $target['event_snapshot']['event_date'] ?? null,
            eventImageUrl: $target['event_snapshot']['event_image_url'] ?? null,
            location: (string) ($target['event_snapshot']['location'] ?? ''),
            hostName: (string) ($target['event_snapshot']['host_name'] ?? ''),
            message: 'Entre para aceitar ou recusar o convite.',
            tags: $target['event_snapshot']['tags'] ?? [],
            attendancePolicy: (string) ($target['event_snapshot']['attendance_policy'] ?? 'free_confirmation_only'),
            status: 'pending',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fromInviteEdge(InviteEdge $edge): array
    {
        $principal = is_array($edge->inviter_principal)
            ? $edge->inviter_principal
            : [];

        return $this->build(
            inviteId: (string) $edge->getAttribute('_id'),
            targetRef: [
                'event_id' => (string) $edge->event_id,
                'occurrence_id' => (string) $edge->occurrence_id,
            ],
            principal: [
                'kind' => (string) ($principal['kind'] ?? ''),
                'id' => (string) ($principal['principal_id'] ?? $principal['id'] ?? ''),
            ],
            inviterDisplayName: $edge->inviter_display_name,
            inviterAvatarUrl: $edge->inviter_avatar_url,
            eventName: (string) ($edge->event_name ?? ''),
            eventDate: $edge->event_date,
            eventImageUrl: $edge->event_image_url,
            location: (string) ($edge->location_label ?? ''),
            hostName: (string) ($edge->host_name ?? ''),
            message: (string) ($edge->message ?? ''),
            tags: is_array($edge->tags) ? $edge->tags : [],
            attendancePolicy: (string) ($edge->attendance_policy ?? 'free_confirmation_only'),
            status: (string) ($edge->status ?? 'pending'),
        );
    }

    /**
     * @param  array{event_id:string,occurrence_id:string}  $targetRef
     * @param  array{kind:string,id:string}  $principal
     * @param  array<int, mixed>  $tags
     * @return array<string, mixed>
     */
    private function build(
        string $inviteId,
        array $targetRef,
        array $principal,
        ?string $inviterDisplayName,
        ?string $inviterAvatarUrl,
        string $eventName,
        ?Carbon $eventDate,
        ?string $eventImageUrl,
        string $location,
        string $hostName,
        string $message,
        array $tags,
        string $attendancePolicy,
        string $status,
    ): array {
        $normalizedInviteId = trim($inviteId);
        $normalizedTargetRef = [
            'event_id' => trim((string) ($targetRef['event_id'] ?? '')),
            'occurrence_id' => trim((string) ($targetRef['occurrence_id'] ?? '')),
        ];
        $normalizedPrincipal = [
            'kind' => trim((string) ($principal['kind'] ?? '')),
            'id' => trim((string) ($principal['id'] ?? '')),
        ];
        $normalizedDisplayName = trim((string) $inviterDisplayName);
        if ($normalizedDisplayName === '') {
            $normalizedDisplayName = 'Um amigo';
        }
        $normalizedAvatarUrl = $this->normalizeOptionalString($inviterAvatarUrl);

        return [
            'id' => $normalizedInviteId,
            'event_id' => $normalizedTargetRef['event_id'],
            'occurrence_id' => $normalizedTargetRef['occurrence_id'],
            'target_ref' => $normalizedTargetRef,
            'event_name' => trim($eventName),
            'event_date' => ($eventDate ?? Carbon::now())->toISOString(),
            'event_image_url' => $this->normalizeOptionalString($eventImageUrl),
            'location' => trim($location),
            'host_name' => trim($hostName),
            'message' => trim($message),
            'tags' => array_values(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) $tag),
                $tags,
            ), static fn (string $tag): bool => $tag !== '')),
            'attendance_policy' => trim($attendancePolicy) !== ''
                ? trim($attendancePolicy)
                : 'free_confirmation_only',
            'inviter_principal' => $normalizedPrincipal,
            'inviter_name' => $normalizedDisplayName,
            'inviter_avatar_url' => $normalizedAvatarUrl,
            'additional_inviters' => [],
            'inviter_candidates' => [[
                'invite_id' => $normalizedInviteId,
                'inviter_principal' => $normalizedPrincipal,
                'display_name' => $normalizedDisplayName,
                'avatar_url' => $normalizedAvatarUrl,
                'status' => trim($status) !== '' ? trim($status) : 'pending',
            ]],
            'social_proof' => [
                'additional_inviter_count' => 0,
            ],
        ];
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
