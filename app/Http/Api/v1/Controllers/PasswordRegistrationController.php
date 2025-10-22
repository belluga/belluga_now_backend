<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Domain\FoundationControlPlane\Identity\Exceptions\IdentityAlreadyExistsException;
use App\Domain\FoundationControlPlane\Identity\PasswordIdentityRegistrar;
use App\Http\Api\v1\Requests\PasswordRegistrationRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class PasswordRegistrationController extends Controller
{
    public function __invoke(
        PasswordRegistrationRequest $request,
        PasswordIdentityRegistrar $registrar
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
