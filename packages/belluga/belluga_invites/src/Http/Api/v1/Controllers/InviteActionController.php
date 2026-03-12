<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Mutations\InviteMutationService;
use Belluga\Invites\Http\Api\v1\Controllers\Concerns\HandlesInviteDomainExceptions;
use Belluga\Invites\Http\Api\v1\Requests\InviteActionRequest;
use Belluga\Invites\Http\Api\v1\Requests\InviteCreateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class InviteActionController extends Controller
{
    use HandlesInviteDomainExceptions;

    public function __construct(
        private readonly InviteMutationService $mutations,
    ) {}

    public function store(InviteCreateRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(fn (): array => $this->mutations->send($request->user(), $request->validated()));
    }

    public function accept(InviteActionRequest $request, string $invite_id): JsonResponse
    {
        $payload = $request->validated();

        return $this->runWithDomainGuard(fn (): array => $this->mutations->accept(
            $request->user(),
            (string) $invite_id,
            isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
        ));
    }

    public function decline(InviteActionRequest $request, string $invite_id): JsonResponse
    {
        $payload = $request->validated();

        return $this->runWithDomainGuard(fn (): array => $this->mutations->decline(
            $request->user(),
            (string) $invite_id,
            isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
        ));
    }
}
