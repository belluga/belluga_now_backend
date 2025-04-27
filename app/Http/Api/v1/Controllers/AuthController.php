<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Api\v1\Requests\LoginEmailRequest;
use App\Http\Api\v1\Requests\RegisterUserRequest;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{

    public function login(LoginEmailRequest $request): JsonResponse
    {

        if(Auth::attempt($request->only('email', 'password'))){
            $user = Auth ::user();
            $token = $user->createToken($request->device_name)->plainTextToken;

            return response()->json([
                "success" => true,
                'data' => [
                    'user' => UserResource::make($user),
                    'token' => $token
                ],
            ],
            );
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'errors' => [
                "credentials" => "The provided credentials are incorrect."
            ]
        ], 403));
    }

    public function loginByToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if(!$user){
            abort(403, "Unauthorized");
        }

        return response()->json([
            "success" => true,
            'data' => [
                'user' => UserResource::make($user)
            ],
        ],
        );
    }

    public function logout(Request $request)
    {
        $request->validate([
            'device' => 'required|string'
        ]);

        $user = $request->user();

        if ($user) {
            $user->tokens()->where("name", $request->device)->delete();
        }

        return response()->noContent();
    }

    /**
     * @group v1
     * @subgroup Auth
     * @unauthenticated
     * @responseFile status=201 responses/api/v1/user.post.success.json
     * @responseFile status=400 scenario="When user already exists" responses/api/v1/user.post.error.json
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        DB::beginTransaction();

        $user = User::create(
            [
                "name" => $request->name,
                "email" => $request->email,
                "password" => $request->password
            ]);

        $token = $user->createToken($request->device_name)->plainTextToken;

        DB::commit();

        return response()->json([
            "success" => true,
            "data" => [
                "token" => $token
            ],
        ],
            status: 201
        );
    }
}
