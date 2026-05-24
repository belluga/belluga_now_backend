<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Social\InviteablePeopleService;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class ContactInviteablesController extends Controller
{
    public function __construct(
        private readonly InviteablePeopleService $inviteablePeople,
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

        $page = $this->boundedPositiveInt($request->query('page'), 1, PHP_INT_MAX);
        $pageSize = $this->boundedPositiveInt(
            $request->query('page_size'),
            InviteablePeopleService::DEFAULT_PAGE_SIZE,
            InviteablePeopleService::MAX_PAGE_SIZE,
        );
        $inviteablePage = $this->inviteablePeople->inviteablePageFor(
            viewer: $user,
            page: $page,
            pageSize: $pageSize,
        );
        $items = $inviteablePage['items'];

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
