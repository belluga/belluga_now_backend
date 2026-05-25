<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Feed;

use Belluga\Invites\Contracts\InviteRecipientProfileProjectionContract;
use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

final class SentInviteStatusQueryService
{
    private const int MAX_ITEMS = 200;

    private const int DEFAULT_SUMMARY_PREVIEW_LIMIT = 5;

    private const int MAX_SUMMARY_PREVIEW_LIMIT = 10;

    /**
     * @var array<int, string>
     */
    private const array CLIENT_CONTROLLED_INVITER_FIELDS = [
        'inviter_id',
        'issued_by_user_id',
        'inviter_principal_id',
        'inviter_principal',
        'tenant_id',
        'tenant',
    ];

    public function __construct(
        private readonly InviteTargetReadContract $targetRead,
        private readonly InviteRecipientProfileProjectionContract $recipientProfiles,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function fetch(mixed $user, array $query, string $requestId): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $this->rejectClientControlledInviterIdentity($query);

        $occurrenceRef = trim((string) ($query['occurrence_id'] ?? ''));
        $eventRef = trim((string) ($query['event_id'] ?? ''));
        if ($occurrenceRef === '') {
            throw new InviteDomainException('occurrence_id_required', 422, 'occurrence_id is required.');
        }

        $target = $this->resolveTarget($occurrenceRef, $eventRef);
        $recipientFilter = $this->recipientFilter($query['recipient_account_profile_ids'] ?? null);

        $inviteQuery = $this->baseInviteQuery($target, $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('_id', 'desc')
            ->limit(self::MAX_ITEMS + 1);

        if ($recipientFilter !== []) {
            $inviteQuery->whereIn('receiver_account_profile_id', $recipientFilter);
        }

        $edges = $inviteQuery->get();
        $truncated = $edges->count() > self::MAX_ITEMS;
        $slice = $edges->take(self::MAX_ITEMS)->values();
        $profileIds = $slice
            ->map(static fn (InviteEdge $edge): string => trim((string) ($edge->receiver_account_profile_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $profilesById = $this->recipientProfiles->profilesByIds($profileIds);

        $items = [];

        foreach ($slice as $edge) {
            $items[] = $this->edgePayload($edge, $profilesById);
        }

        return [
            'data' => [
                'event_id' => $target['event_id'],
                'occurrence_id' => $target['occurrence_id'],
                'items' => $items,
            ],
            'metadata' => [
                'request_id' => $requestId,
                'truncated' => $truncated,
                'next_cursor' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function fetchSummary(mixed $user, array $query, string $requestId): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $this->rejectClientControlledInviterIdentity($query);

        $occurrenceRef = trim((string) ($query['occurrence_id'] ?? ''));
        $eventRef = trim((string) ($query['event_id'] ?? ''));
        if ($occurrenceRef === '') {
            throw new InviteDomainException('occurrence_id_required', 422, 'occurrence_id is required.');
        }

        $target = $this->resolveTarget($occurrenceRef, $eventRef);
        $previewLimit = $this->previewLimit($query['preview_limit'] ?? null);
        $baseQuery = $this->baseInviteQuery($target, $userId);

        $pending = (clone $baseQuery)
            ->whereIn('status', ['pending', 'viewed'])
            ->count();
        $accepted = (clone $baseQuery)
            ->where('status', 'accepted')
            ->count();
        $declined = (clone $baseQuery)
            ->where('status', 'declined')
            ->count();
        $terminalHidden = (clone $baseQuery)
            ->whereNotIn('status', ['pending', 'viewed', 'accepted', 'declined'])
            ->count();
        $totalSent = (clone $baseQuery)->count();

        $previewEdges = (clone $baseQuery)
            ->orderBy('created_at', 'desc')
            ->orderBy('_id', 'desc')
            ->limit($previewLimit)
            ->get();

        return [
            'data' => [
                'event_id' => $target['event_id'],
                'occurrence_id' => $target['occurrence_id'],
                'summary' => [
                    'pending' => $pending,
                    'accepted' => $accepted,
                    'declined' => $declined,
                    'terminal_hidden' => $terminalHidden,
                    'total_visible' => $pending + $accepted + $declined,
                    'total_sent' => $totalSent,
                ],
                'preview' => $this->itemsFromEdges($previewEdges),
            ],
            'metadata' => [
                'request_id' => $requestId,
                'preview_limit' => $previewLimit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<int, string>  $recipientAccountProfileIds
     * @return array<string, array<string, mixed>>
     */
    public function statusMapForRecipients(mixed $user, array $query, array $recipientAccountProfileIds): array
    {
        $userId = $this->userId($user);
        if ($userId === null) {
            throw new InviteDomainException('auth_required', 401);
        }

        $this->rejectClientControlledInviterIdentity($query);

        $occurrenceRef = trim((string) ($query['occurrence_id'] ?? ''));
        $eventRef = trim((string) ($query['event_id'] ?? ''));
        if ($occurrenceRef === '') {
            throw new InviteDomainException('occurrence_id_required', 422, 'occurrence_id is required.');
        }

        $recipientFilter = $this->recipientFilter($recipientAccountProfileIds);
        if ($recipientFilter === []) {
            return [];
        }

        $target = $this->resolveTarget($occurrenceRef, $eventRef);
        $edges = $this->baseInviteQuery($target, $userId)
            ->whereIn('receiver_account_profile_id', $recipientFilter)
            ->orderBy('created_at', 'desc')
            ->orderBy('_id', 'desc')
            ->limit(count($recipientFilter) * 2)
            ->get();

        $items = $this->itemsFromEdges($edges);
        $byProfileId = [];
        foreach ($items as $item) {
            $profileId = trim((string) ($item['receiver_account_profile_id'] ?? ''));
            if ($profileId === '' || array_key_exists($profileId, $byProfileId)) {
                continue;
            }
            $byProfileId[$profileId] = $item;
        }

        return $byProfileId;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function rejectClientControlledInviterIdentity(array $query): void
    {
        foreach (self::CLIENT_CONTROLLED_INVITER_FIELDS as $field) {
            if (array_key_exists($field, $query)) {
                throw new InviteDomainException(
                    'client_inviter_identity_forbidden',
                    422,
                    'Inviter identity is derived from the authenticated user.'
                );
            }
        }
    }

    /**
     * @return array{event_id:string,occurrence_id:string}
     */
    private function resolveTarget(string $occurrenceRef, string $eventRef): array
    {
        if ($eventRef !== '') {
            $event = $this->targetRead->findEventByIdOrSlug($eventRef);
            if (! $event) {
                throw new InviteDomainException('occurrence_not_found', 404);
            }

            $occurrence = $this->targetRead->findOccurrenceForEvent((string) $event['id'], $occurrenceRef);
            if (! $occurrence || ! (bool) ($occurrence['is_event_published'] ?? false)) {
                $resolvedOccurrence = $this->targetRead->findOccurrenceByIdOrSlug($occurrenceRef);
                if ($resolvedOccurrence && (string) ($resolvedOccurrence['event_id'] ?? '') !== (string) $event['id']) {
                    throw new InviteDomainException(
                        'occurrence_event_mismatch',
                        422,
                        'event_id does not match the occurrence parent event.'
                    );
                }

                throw new InviteDomainException('occurrence_not_found', 404);
            }

            return [
                'event_id' => (string) $event['id'],
                'occurrence_id' => (string) $occurrence['id'],
            ];
        }

        $occurrence = $this->targetRead->findOccurrenceByIdOrSlug($occurrenceRef);
        if (! $occurrence || ! (bool) ($occurrence['is_event_published'] ?? false)) {
            throw new InviteDomainException('occurrence_not_found', 404);
        }

        $eventId = trim((string) ($occurrence['event_id'] ?? ''));
        if ($eventId === '') {
            throw new InviteDomainException('occurrence_not_found', 404);
        }

        return [
            'event_id' => $eventId,
            'occurrence_id' => (string) $occurrence['id'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function recipientFilter(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];
        if (count($values) > self::MAX_ITEMS) {
            throw new InviteDomainException(
                'too_many_recipient_account_profile_ids',
                422,
                'recipient_account_profile_ids accepts at most 200 ids.'
            );
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ))));
    }

    /**
     * @param  array{event_id:string,occurrence_id:string}  $target
     */
    private function baseInviteQuery(array $target, string $userId): mixed
    {
        return InviteEdge::query()
            ->where('event_id', $target['event_id'])
            ->where('occurrence_id', $target['occurrence_id'])
            ->where('issued_by_user_id', $userId);
    }

    private function previewLimit(mixed $raw): int
    {
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_SUMMARY_PREVIEW_LIMIT;

        return max(1, min(self::MAX_SUMMARY_PREVIEW_LIMIT, $value));
    }

    /**
     * @param  iterable<int, InviteEdge>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function itemsFromEdges(iterable $edges): array
    {
        $edgeList = collect($edges)->values();
        $profileIds = $edgeList
            ->map(static fn (InviteEdge $edge): string => trim((string) ($edge->receiver_account_profile_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $profilesById = $this->recipientProfiles->profilesByIds($profileIds);

        return $edgeList
            ->map(fn (InviteEdge $edge): array => $this->edgePayload($edge, $profilesById))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{
     *     receiver_account_profile_id:string,
     *     receiver_user_id:?string,
     *     display_name:?string,
     *     avatar_url:?string
     * }>  $profilesById
     * @return array<string, mixed>
     */
    private function edgePayload(InviteEdge $edge, array $profilesById): array
    {
        $receiverAccountProfileId = trim((string) ($edge->receiver_account_profile_id ?? ''));
        $profile = $profilesById[$receiverAccountProfileId] ?? null;
        $status = $this->status((string) ($edge->status ?? 'pending'));
        $visibility = in_array($status, ['pending', 'accepted', 'declined'], true) ? 'visible' : 'hidden';
        $bucket = match ($status) {
            'pending' => 'pending',
            'accepted' => 'accepted',
            'declined' => 'declined',
            default => 'none',
        };

        return [
            'invite_id' => (string) $edge->getAttribute('_id'),
            'recipient_key' => 'account_profile:'.$receiverAccountProfileId,
            'receiver_account_profile_id' => $receiverAccountProfileId,
            'receiver_user_id' => (string) ($profile['receiver_user_id'] ?? $edge->receiver_user_id ?? '') ?: null,
            'display_name' => (string) ($profile['display_name'] ?? ''),
            'avatar_url' => $profile['avatar_url'] ?? null,
            'status' => $status,
            'ui_visibility' => $visibility,
            'blocks_reinvite' => true,
            'counts_bucket' => $bucket,
            'sent_at' => $this->isoString($edge->created_at),
            'responded_at' => $this->respondedAt($status, $edge),
            'supersession_reason' => $edge->supersession_reason ? (string) $edge->supersession_reason : null,
        ];
    }

    private function status(string $status): string
    {
        $normalized = trim($status);
        if ($normalized === 'viewed') {
            return 'pending';
        }

        return in_array($normalized, ['pending', 'accepted', 'declined', 'expired', 'superseded', 'suppressed'], true)
            ? $normalized
            : 'suppressed';
    }

    private function respondedAt(string $status, InviteEdge $edge): ?string
    {
        return match ($status) {
            'accepted' => $this->isoString($edge->accepted_at),
            'declined' => $this->isoString($edge->declined_at),
            'expired' => $this->isoString($edge->expires_at),
            'superseded', 'suppressed' => $this->isoString($edge->updated_at),
            default => null,
        };
    }

    private function isoString(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime())->toISOString();
        }
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toISOString();
        }

        return null;
    }

    private function userId(mixed $user): ?string
    {
        if (! is_object($user)) {
            return null;
        }

        $id = null;
        if (method_exists($user, 'getKey')) {
            $id = $user->getKey();
        }
        if ($id === null && property_exists($user, '_id')) {
            $id = $user->_id;
        }
        if ($id === null && method_exists($user, 'getAttribute')) {
            $id = $user->getAttribute('_id');
        }
        if ($id === null && method_exists($user, 'getAuthIdentifier')) {
            $id = $user->getAuthIdentifier();
        }

        return is_scalar($id) ? (string) $id : null;
    }
}
