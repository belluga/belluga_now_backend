<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Social\InviteablePeopleService;
use App\Models\Tenants\AccountUser;
use Belluga\Invites\Application\Feed\SentInviteStatusQueryService;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class ContactInviteablesController extends Controller
{
    public function __construct(
        private readonly InviteablePeopleService $inviteablePeople,
        private readonly SentInviteStatusQueryService $sentStatuses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();
        if (! $user instanceof AccountUser) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $occurrenceId = trim((string) $request->query('occurrence_id', ''));
        $eventId = trim((string) $request->query('event_id', ''));
        if ($occurrenceId === '' && $eventId === '') {
            return response()->json([
                'items' => $this->inviteablePeople->inviteableItemsFor($user),
            ]);
        }

        $page = $this->boundedPositiveInt($request->query('page'), 1, PHP_INT_MAX);
        $pageSize = $this->boundedPositiveInt($request->query('page_size'), 50, 100);
        $inviteablePage = $this->inviteablePeople->inviteablePageFor(
            viewer: $user,
            page: $page,
            pageSize: $pageSize,
        );
        $items = $inviteablePage['items'];

        try {
            $statusesByProfileId = $this->sentStatuses->statusMapForRecipients(
                user: $user,
                query: $request->query(),
                recipientAccountProfileIds: array_values(array_unique(array_filter(array_map(
                    static fn (array $item): string => trim((string) ($item['receiver_account_profile_id'] ?? '')),
                    $items,
                )))),
            );
        } catch (InviteDomainException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'hints' => [],
                ],
                'metadata' => [
                    'request_id' => $requestId,
                ],
            ], $exception->httpStatus);
        }

        foreach ($items as &$item) {
            $profileId = trim((string) ($item['receiver_account_profile_id'] ?? ''));
            $item['sent_invite_status'] = $profileId === ''
                ? null
                : ($statusesByProfileId[$profileId] ?? null);
        }
        unset($item);

        return response()->json([
            'items' => $items,
            'metadata' => [
                'request_id' => $requestId,
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => $inviteablePage['has_more'],
            ],
        ]);
    }

    private function boundedPositiveInt(mixed $value, int $default, int $max): int
    {
        $parsed = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $parsed));
    }
}
