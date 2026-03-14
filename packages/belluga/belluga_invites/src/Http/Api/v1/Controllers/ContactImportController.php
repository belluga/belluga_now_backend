<?php

declare(strict_types=1);

namespace Belluga\Invites\Http\Api\v1\Controllers;

use Belluga\Invites\Application\Contacts\ContactImportService;
use Belluga\Invites\Http\Api\v1\Controllers\Concerns\HandlesInviteDomainExceptions;
use Belluga\Invites\Http\Api\v1\Requests\ContactsImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ContactImportController extends Controller
{
    use HandlesInviteDomainExceptions;

    public function __construct(
        private readonly ContactImportService $imports,
    ) {}

    public function store(ContactsImportRequest $request): JsonResponse
    {
        return $this->runWithDomainGuard(fn (): array => $this->imports->import($request->user(), $request->validated()));
    }
}
