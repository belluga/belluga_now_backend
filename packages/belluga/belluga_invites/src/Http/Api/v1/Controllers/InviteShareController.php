<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Mutations\InviteShareService;
use Belluga\Invites\Http\Api\v1\Controllers\Concerns\HandlesInviteDomainExceptions;
use Belluga\Invites\Http\Api\v1\Requests\InviteActionRequest;
use Belluga\Invites\Http\Api\v1\Requests\InviteShareCreateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class InviteShareController extends Controller
{
    use HandlesInviteDomainExceptions;

    public function __construct(
        private readonly InviteShareService $shareService,
    ) {}

    public function store(InviteShareCreateRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(fn (): array => $this->shareService->create($request->user(), $request->validated()));
    }

    public function accept(InviteActionRequest $request, string $code): JsonResponse
    {
        $payload = $request->validated();

        return $this->runWithDomainGuard(fn (): array => $this->shareService->accept(
            $request->user(),
            (string) $code,
            isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
        ));
    }
}
