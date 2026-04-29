<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Social\InviteablePeopleService;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ContactInviteablesController extends Controller
{
    public function __construct(
        private readonly InviteablePeopleService $inviteablePeople,
    ) {}

    public function index(): JsonResponse
    {
        $user = request()->user();
        if (! $user instanceof AccountUser) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'items' => $this->inviteablePeople->inviteableItemsFor($user),
        ]);
    }
}
