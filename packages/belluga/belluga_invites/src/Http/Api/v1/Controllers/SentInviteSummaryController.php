<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Feed\SentInviteStatusQueryService;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class SentInviteSummaryController extends Controller
{
    public function __construct(
        private readonly SentInviteStatusQueryService $sentStatuses,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $requestId = (string) Str::uuid();

        try {
            return response()->json($this->sentStatuses->fetchSummary(
                user: $request->user(),
                query: $request->query(),
                requestId: $requestId,
            ));
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
    }
}
