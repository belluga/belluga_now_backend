<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Social\ContactGroupService;
use App\Http\Api\v1\Requests\ContactGroupStoreRequest;
use App\Http\Api\v1\Requests\ContactGroupUpdateRequest;
use App\Models\Tenants\AccountUser;
use Belluga\Invites\Http\Api\v1\Controllers\Concerns\HandlesInviteDomainExceptions;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ContactGroupController extends Controller
{
    use HandlesInviteDomainExceptions;

    public function __construct(
        private readonly ContactGroupService $groups,
    ) {}

    public function index(): JsonResponse
    {
        $user = $this->accountUser();

        return response()->json([
            'data' => $this->groups->list($user),
        ]);
    }

    public function store(ContactGroupStoreRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(
            fn (): JsonResponse => response()->json([
                'data' => $this->groups->create($this->accountUser(), $request->validated()),
            ], 201),
        );
    }

    public function update(ContactGroupUpdateRequest $request, string $tenant_domain, string $group_id): JsonResponse
    {
        return $this->runWithDomainGuard(
            fn (): JsonResponse => response()->json([
                'data' => $this->groups->update($this->accountUser(), $group_id, $request->validated()),
            ]),
        );
    }

    public function destroy(string $tenant_domain, string $group_id): JsonResponse|Response
    {
        return $this->runWithDomainGuard(function () use ($group_id): Response {
            $this->groups->delete($this->accountUser(), $group_id);

            return response()->noContent();
        });
    }

    private function accountUser(): AccountUser
    {
        $user = request()->user();
        if (! $user instanceof AccountUser) {
            throw new InviteDomainException('auth_required', 401);
        }

        return $user;
    }
}
