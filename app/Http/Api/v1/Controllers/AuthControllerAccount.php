<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Auth\AccountAuthenticationService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Http\Api\v1\Requests\LoginEmailRequest;
use App\Http\Api\v1\Requests\RegisterUserRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthControllerAccount extends Controller
{
    public function __construct(
        private readonly AccountAuthenticationService $authentication
    ) {
    }

    public function login(LoginEmailRequest $request): JsonResponse
    {
        try {
            $result = $this->authentication->login(
                $request->email,
                $request->password,
                $request->device_name
            );
        } catch (InvalidCredentialsException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'credentials' => $exception->getMessage(),
                ],
            ], 403);
        }

        return response()->json([
            'data' => [
                'user' => UserResource::make($result->user),
                'token' => $result->plainTextToken,
            ],
        ]);
    }

    public function loginByToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthorized');
        }

        return response()->json([
            'data' => [
                'user' => UserResource::make($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'all_devices' => 'boolean',
            'device' => 'required_if:all_devices,false|string',
        ]);

        $user = $request->user();

        if ($user) {
            $this->authentication->logout(
                $user,
                (bool) ($validated['all_devices'] ?? false),
                $validated['device'] ?? null
            );
        }

        return response()->json();
    }

    public function register(RegisterUserRequest $request): JsonResponse
    {
        abort(405, 'Registration is not available for account users.');
    }
}
