<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Domain\FoundationControlPlane\Identity\Exceptions\IdentityAlreadyExistsException;
use App\Domain\FoundationControlPlane\Identity\AnonymousIdentityMerger;
use App\Domain\FoundationControlPlane\Identity\PasswordIdentityRegistrar;
use App\Http\Api\v1\Requests\PasswordRegistrationRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountUser;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Illuminate\Support\Collection;

class PasswordRegistrationController extends Controller
{
    public function __invoke(
        PasswordRegistrationRequest $request,
        PasswordIdentityRegistrar $registrar,
        AnonymousIdentityMerger $identityMerger
    ): JsonResponse {
        $tenant = Tenant::current();

        if (! $tenant) {
            abort(404, 'Tenant not resolved for password registration.');
        }

        $validated = $request->validated();

        try {
            $user = $registrar->register([
                'name' => $validated['name'],
                'emails' => [$validated['email']],
                'password' => $validated['password'],
            ]);
        } catch (IdentityAlreadyExistsException $exception) {
            return response()->json([
                'message' => 'An identity with this email already exists.',
                'errors' => [
                    'email' => ['This email is already registered for the tenant.'],
                ],
            ], 422);
        }

        $anonymousUserIds = Collection::make($validated['anonymous_user_ids'] ?? [])
            ->unique()
            ->values();

        if ($anonymousUserIds->isNotEmpty()) {
            Tenant::current()?->makeCurrent();

            $objectIds = $anonymousUserIds->map(static fn (string $id): ObjectId => new ObjectId($id));
            $anonymousUsers = AccountUser::query()
                ->whereIn('_id', $objectIds->all())
                ->get();

            if ($anonymousUsers->count() !== $anonymousUserIds->count()) {
                throw ValidationException::withMessages([
                    'anonymous_user_ids' => ['One or more anonymous identities could not be found.'],
                ]);
            }

            if ($anonymousUsers->contains(static fn (AccountUser $accountUser): bool => $accountUser->identity_state !== 'anonymous')) {
                throw ValidationException::withMessages([
                    'anonymous_user_ids' => ['Only anonymous identities can be merged during registration.'],
                ]);
            }

            $identityMerger->merge($user, $anonymousUsers, (string) $user->_id);
        }

        $token = $user->createToken('auth:password-register');

        return response()->json([
            'data' => [
                'user_id' => (string) $user->_id,
                'identity_state' => $user->identity_state,
                'token' => $token->plainTextToken,
            ],
        ], 201);
    }
}
