<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Feed\InviteFeedQueryService;
use Belluga\Invites\Http\Api\v1\Requests\InviteListRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class InviteFeedController extends Controller
{
    public function __construct(
        private readonly InviteFeedQueryService $feeds,
    ) {}

    public function index(InviteListRequest $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user ? (string) $user->getAuthIdentifier() : '';

        return response()->json($this->feeds->fetchForUser(
            userId: $userId,
            page: (int) ($request->validated('page') ?? 1),
            pageSize: (int) ($request->validated('page_size') ?? 20),
        ));
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->feeds->settingsPayload());
    }
}
