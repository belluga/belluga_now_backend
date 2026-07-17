<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Profiles\CurrentTenantAccountDeletionService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Http\Api\v1\Requests\DeleteCurrentTenantAccountRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Symfony\Component\HttpFoundation\Response;

final class CurrentTenantAccountDeletionController extends Controller
{
    public function __invoke(
        DeleteCurrentTenantAccountRequest $request,
        CurrentTenantAccountDeletionService $deletion,
    ): Response {
        $principal = $request->user();
        if (
            ! $principal instanceof AccountUser
            || ! in_array((string) $principal->identity_state, ['registered', 'validated'], true)
        ) {
            abort(403);
        }

        try {
            $deletion->delete(Tenant::resolve(), $principal);
        } catch (ConcurrencyConflictException) {
            return response()->json([
                'message' => 'A concurrency conflict occurred. Please try again.',
            ], 409);
        }

        return response()->noContent();
    }
}
