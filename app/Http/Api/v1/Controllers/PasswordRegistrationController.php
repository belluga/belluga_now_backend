<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Domain\Identity\PasswordIdentityRegistrar;
use App\Domain\Identity\AnonymousIdentityMerger;
use App\Exceptions\Identity\IdentityAlreadyExistsException;
use App\Http\Api\v1\Requests\PasswordRegistrationRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;

class PasswordRegistrationController extends Controller
{
    public function __invoke(
        PasswordRegistrationRequest $request,
        PasswordIdentityRegistrar $registrar,
        AnonymousIdentityMerger $identityMerger
    ): JsonResponse {
        $tenant = Tenant::resolve();

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
            $tenant->makeCurrent();

            $anonymousUsers = $anonymousUserIds
                ->map(function (string $id): AccountUser {
                    if (! preg_match('/^[a-f0-9]{24}$/i', $id)) {
                        throw ValidationException::withMessages([
                            'anonymous_user_ids' => ['One or more anonymous identities was not a valid ObjectId String.'],
                        ]);
                    }

                    /** @var AccountUser|null $anonymousUser */
                    $anonymousUser = AccountUser::query()->find($id);

                    if ($anonymousUser === null) {
                        throw ValidationException::withMessages([
                            'anonymous_user_ids' => ['One or more anonymous identities could not be found.'],
                        ]);
                    }

                    if ($anonymousUser->identity_state !== 'anonymous') {
                        throw ValidationException::withMessages([
                            'anonymous_user_ids' => ['Only anonymous identities can be merged during registration.'],
                        ]);
                    }

                    return $anonymousUser;
                })
                ->values();

            $maxAttempts = 3;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $identityMerger->merge($user, $anonymousUsers, (string) $user->_id);
                    break;
                } catch (ConcurrencyConflictException $e) {
                    if ($attempt === $maxAttempts) {
                        return response()->json([
                            'message' => 'A concurrency conflict occurred. Please try again.',
                        ], 409);
                    }
                    usleep(100000);
                }
            }
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
